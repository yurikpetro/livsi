<?php

namespace Tests\Feature;

use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\DeliverySelection;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['delivery_test' => true]);
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->seed(CatalogSeeder::class);
        Setting::put('vat_rate_default', '20');
        Setting::put('delivery_calculation_enabled', true);
        Setting::put('delivery_origin_address', 'Ставрополь, Ленина, 1');
        Setting::put('delivery_pickup_type', 1);
        Setting::put('delivery_padding_mm', 10);
        Setting::put('delivery_packaging_weight_g', 90);
        config([
            'livsi.yookassa.shop_id' => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
        ]);
        Mail::fake();
        Http::fake([
            '*/calculator' => Http::response(['deliveryToPoint' => [[
                'providerKey' => 'cdek', 'tariffs' => [[
                    'tariffId' => 7, 'deliveryCost' => 300, 'feesIncluded' => true,
                    'pickupTypes' => [1], 'deliveryTypes' => [2], 'pointIds' => [42, 27948],
                ]],
            ]]]),
            '*/payments/delivery_test' => Http::response(['id' => 'delivery_test', 'status' => 'pending']),
            '*/payments' => Http::response([
                'id' => 'delivery_test',
                'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->token = Str::random(32);
        $variant = ProductVariant::whereHas('quota', fn ($query) => $query->where('allocated', '>', 5))->firstOrFail();
        $variant->update(['dimensions_verified' => true, 'weight_g' => 250, 'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 100]);
        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => 1]);
    }

    private function checkout(array $delivery): TestResponse
    {
        if (! empty($delivery['delivery_point_id']) && ($delivery['delivery_point_method'] ?? $delivery['delivery_method'] ?? null) === DeliverySelection::CDEK_POINT) {
            $quote = $this->withUnencryptedCookie(CartService::COOKIE, $this->token)->postJson(route('delivery.quote'), [
                'delivery_city' => $delivery['delivery_city'],
                'delivery_region' => $delivery['delivery_region'] ?? null,
                'delivery_point_method' => DeliverySelection::CDEK_POINT,
                'delivery_point_id' => $delivery['delivery_point_id'],
            ]);
            $delivery['delivery_quote_token'] = $quote->json('token');
        }

        return $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('checkout.store'), array_merge([
                'name' => 'Мария',
                'phone' => '+7 900 000-00-00',
                'email' => 'maria@example.test',
                'consent' => '1',
            ], $delivery));
    }

    public function test_checkout_offers_pickup_and_local_courier_without_forcing_a_carrier(): void
    {
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 100000000]);

        $response = $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))->assertOk();

        $response->assertSee('В пункт выдачи')
            ->assertSee('Курьер по Ставрополю')
            ->assertSee('value="pickup_point"', false)
            ->assertDontSee('<option value="ozon_point"', false)
            ->assertSee('Доставка рассчитается после выбора пункта выдачи')
            ->assertSee('data-free-shipping="0"', false)
            ->assertDontSee('Согласовать доставку с менеджером');
    }

    public function test_checkout_shows_free_delivery_when_the_active_rule_is_unlocked(): void
    {
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 1]);

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('Доставка рассчитается после выбора пункта выдачи')
            ->assertSee('data-free-shipping="1"', false)
            ->assertDontSee('стоимость и пункт выдачи менеджер согласует отдельно');
    }

    public function test_carrier_choice_without_a_quote_cannot_create_an_order(): void
    {
        $this->checkout([
            'delivery_method' => DeliverySelection::CDEK_POINT,
            'delivery_city' => 'Казань',
            'delivery_point_hint' => 'Ближе к центру',
            'delivery_address' => 'Не должен сохраниться',
        ])->assertSessionHasErrors('delivery_quote');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_address_hint_cannot_replace_a_calculated_pickup_point(): void
    {
        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Армавир',
            'delivery_region' => 'Краснодарский край',
            'delivery_point_hint' => 'Возле вокзала',
        ])->assertSessionHasErrors('delivery_quote');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_generic_pickup_rejects_point_id_without_a_carrier(): void
    {
        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Москва',
            'delivery_point_id' => '42',
        ])->assertSessionHasErrors('delivery_point_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_includes_connected_cdek_in_the_shared_pickup_map(): void
    {
        config([
            'livsi.apiship.token' => 'example-token',
            'livsi.apiship.active_providers' => ['cdek', 'ozonlog', 'yataxi'],
        ]);

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))
            ->assertSee('data-methods="cdek_point,yandex_point,ozon_point"', false)
            ->assertDontSee('example-token');
    }

    public function test_generic_pickup_saves_the_verified_cdek_point_and_destination(): void
    {
        config([
            'livsi.apiship.token' => 'example-token',
            'livsi.apiship.active_providers' => ['cdek', 'ozonlog', 'yataxi'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '27948', 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Ставрополь', 'region' => 'Ставропольский',
                'availableOperation' => 3, 'enabled' => true,
                'address' => 'Ставрополь, ул. Пирогова, 59',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Александровское',
            'delivery_region' => 'Ставропольский край',
            'delivery_point_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '27948',
            'delivery_point_address' => 'Подменённый адрес',
        ])->assertRedirect('https://example.test/pay');

        $this->assertDatabaseHas('orders', [
            'delivery_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '27948',
            'delivery_point_address' => 'Ставрополь, ул. Пирогова, 59',
            'delivery_city' => 'Ставрополь',
            'delivery_region' => 'Ставропольский край',
        ]);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'id=27948;providerKey=cdek'));
        Mail::assertQueued(OrderPlaced::class);
    }

    public function test_generic_pickup_saves_the_carrier_from_a_verified_point(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => 42, 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Москва', 'region' => 'Москва', 'availableOperation' => 2,
                'enabled' => true, 'address' => 'Москва, улица 1',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Москва',
            'delivery_region' => 'Москва',
            'delivery_point_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '42',
        ])->assertRedirect('https://example.test/pay');

        $order = Order::firstOrFail();
        $this->assertSame(DeliverySelection::CDEK_POINT, $order->delivery_method);
        $this->assertSame('42', $order->delivery_point_id);
    }

    public function test_point_from_another_region_blocks_checkout(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => 42, 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Александровск', 'region' => 'Пермский край', 'availableOperation' => 2,
                'enabled' => true, 'address' => 'Александровск, улица 1',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Александровск',
            'delivery_region' => 'Ставропольский край',
            'delivery_point_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '42',
        ])->assertSessionHasErrors('delivery_quote');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_local_courier_requires_stavropol_and_an_address(): void
    {
        $this->checkout([
            'delivery_method' => DeliverySelection::STAVROPOL_COURIER,
            'delivery_city' => 'Москва',
        ])->assertSessionHasErrors(['delivery_city', 'delivery_address']);

        $this->assertDatabaseCount('orders', 0);

        $this->checkout([
            'delivery_method' => DeliverySelection::STAVROPOL_COURIER,
            'delivery_city' => ' ставрополь ',
            'delivery_address' => 'ул. Ленина, 10',
        ])->assertSessionHasErrors('delivery_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unknown_or_missing_method_cannot_create_order(): void
    {
        $this->checkout(['delivery_city' => 'Москва'])->assertSessionHasErrors('delivery_method');
        $this->checkout(['delivery_method' => 'fake', 'delivery_city' => 'Москва'])
            ->assertSessionHasErrors('delivery_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_selected_carrier_point_is_verified_before_it_is_saved(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '42', 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Москва', 'availableOperation' => 2,
                'enabled' => true, 'address' => 'Москва, улица 1',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::CDEK_POINT,
            'delivery_city' => 'Москва',
            'delivery_point_id' => '42',
            'delivery_point_hint' => 'Москва, улица 1',
        ])->assertRedirect('https://example.test/pay');

        $order = Order::firstOrFail();
        $this->assertSame('42', $order->delivery_point_id);
        $this->assertSame('Москва, улица 1', $order->delivery_point_address);
    }

    public function test_point_in_neighboring_city_of_selected_region_is_saved_as_actual_destination(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '42', 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Пятигорск', 'region' => 'Ставропольский', 'availableOperation' => 2,
                'enabled' => true, 'address' => 'Пятигорск, улица 1',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Александровское',
            'delivery_region' => 'Ставропольский край',
            'delivery_point_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '42',
        ])->assertRedirect('https://example.test/pay');

        $order = Order::firstOrFail();
        $this->assertSame('42', $order->delivery_point_id);
        $this->assertSame('Пятигорск', $order->delivery_city);
        $this->assertSame('Пятигорск, улица 1', $order->delivery_point_address);
    }

    public function test_point_outside_selected_region_blocks_checkout(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '42', 'providerKey' => 'cdek', 'countryCode' => 'RU',
                'city' => 'Александровск', 'region' => 'Пермский край', 'availableOperation' => 2,
                'enabled' => true, 'address' => 'Александровск, улица 1',
            ]]]),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_city' => 'Александровское',
            'delivery_region' => 'Ставропольский край',
            'delivery_point_method' => DeliverySelection::CDEK_POINT,
            'delivery_point_id' => '42',
        ])->assertSessionHasErrors('delivery_quote');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_apiship_outage_blocks_checkout(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['cdek']]);
        Http::fake([
            '*/lists/points*' => Http::response(['message' => 'unavailable'], 503),
            '*/payments' => Http::response([
                'id' => 'delivery_test', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
            ]),
        ]);

        $this->checkout([
            'delivery_method' => DeliverySelection::CDEK_POINT,
            'delivery_city' => 'Москва',
            'delivery_point_id' => '42',
            'delivery_point_hint' => 'Москва, улица 1',
        ])->assertSessionHasErrors('delivery_quote');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
