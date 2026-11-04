<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Services\DeliverySelection;
use App\Services\FulfillmentService;
use App\Services\PaymentService;
use App\Support\Fulfillment;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Курьер по Ставрополю, подсказка о дорогом или долгом пункте выдачи
 * и сохранение выбранного пункта после отказа в промокоде.
 */
class CourierAndHintsTest extends TestCase
{
    use RefreshDatabase;

    private const LIVSIPRO_TOKEN = 'livsipro-test-token-0123456789-abcdefghijklmnop';

    private string $cartToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->seed(CatalogSeeder::class);
        Setting::put('vat_not_payer', true);
        Setting::put('delivery_calculation_enabled', true);
        Setting::put('delivery_origin_address', 'Ставрополь, улица Ленина, 1');
        Setting::put('delivery_pickup_type', 1);
        Setting::put('delivery_padding_mm', 11);
        Setting::put('delivery_packaging_weight_g', 90);
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 100000000]);
        config(['livsi.apiship.token' => 'test-token', 'livsi.apiship.active_providers' => ['cdek', 'yataxi'],
            'livsi.yookassa.shop_id' => '123', 'livsi.yookassa.secret_key' => 'test_secret',
            'livsi.livsipro.token' => self::LIVSIPRO_TOKEN, 'livsi.livsipro.ips' => []]);
        Mail::fake();
        $this->fakeCarrier();
        $this->cartToken = Str::random(32);
        Cart::create(['token' => $this->cartToken, 'last_activity_at' => now()]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();
        $variant->update(['dimensions_verified' => true, 'weight_g' => 250, 'length_mm' => 61, 'width_mm' => 45, 'height_mm' => 181]);
        $this->withCookie(CartService::COOKIE, $this->cartToken)
            ->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => 2]);
    }

    /**
     * Три пункта Яндекса в Ставрополе: в «42» служба везёт за 918,59 ₽ и 9 дней,
     * в остальные — за 158,53 ₽ и 3 дня (как было на самом деле).
     *
     * @param  array<string, array{0: float, 1: int}>  $prices
     * @param  array<string, string>  $cities  пункты, которые числятся не за Ставрополем
     */
    private function fakeCarrier(array $prices = ['42' => [918.59, 9], '43' => [158.53, 3], '44' => [158.53, 3]], array $cities = []): void
    {
        $rows = array_map(fn (string $id): array => ['id' => $id, 'providerKey' => 'yataxi', 'countryCode' => 'RU',
            'city' => $cities[$id] ?? 'Ставрополь', 'region' => 'Ставропольский край', 'address' => 'Ставрополь, пункт '.$id,
            'availableOperation' => 2, 'enabled' => true], array_map('strval', array_keys($prices)));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/lists/points*' => function (Request $request) use ($rows) {
                preg_match('/(?:^|;)id=(\d+)/', (string) ($request['filter'] ?? ''), $match);

                return Http::response(['rows' => array_values(array_filter($rows,
                    fn (array $row): bool => ! $match || $row['id'] === $match[1])), 'meta' => ['total' => count($rows)]]);
            },
            '*/calculator' => function (Request $request) use ($prices) {
                [$cost, $days] = $prices[(string) $request['pointOutId']];

                return Http::response(['deliveryToPoint' => [['providerKey' => 'yataxi', 'tariffs' => [[
                    'tariffId' => 7, 'tariffName' => 'Посылка', 'deliveryCost' => $cost, 'feesIncluded' => true,
                    'pickupTypes' => [1], 'deliveryTypes' => [2], 'pointIds' => [(int) $request['pointOutId']],
                    'calendarDaysMin' => $days, 'calendarDaysMax' => $days,
                ]]]]]);
            },
            '*/payments' => Http::response(['id' => 'courier_payment', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay']]),
        ]);
    }

    private function enableCourier(int $price = 25000): void
    {
        Setting::put('stavropol_courier_enabled', true);
        Setting::put('stavropol_courier_price', $price);
        Setting::put('stavropol_courier_note', 'Привезём сегодня или завтра.');
    }

    private function quote(array $destination): TestResponse
    {
        return $this->withCookie(CartService::COOKIE, $this->cartToken)->postJson(route('delivery.quote'), $destination + [
            'delivery_city' => 'Ставрополь', 'delivery_region' => 'Ставропольский край',
        ]);
    }

    private function courierQuote(array $overrides = []): TestResponse
    {
        return $this->quote($overrides + ['delivery_point_method' => DeliverySelection::STAVROPOL_COURIER, 'delivery_point_id' => '']);
    }

    private function pointQuote(string $id): TestResponse
    {
        return $this->quote(['delivery_point_method' => DeliverySelection::YANDEX_POINT, 'delivery_point_id' => $id]);
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->withCookie(CartService::COOKIE, $this->cartToken)->post(route('checkout.store'), array_merge([
            'name' => 'Мария', 'phone' => '+79000000000', 'email' => 'maria@example.test', 'consent' => '1',
            'delivery_city' => 'Ставрополь', 'delivery_region' => 'Ставропольский край',
            'delivery_method' => DeliverySelection::STAVROPOL_COURIER, 'delivery_address' => 'ул. Ленина, 10, кв. 5',
        ], $overrides));
    }

    private function livsipro(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::LIVSIPRO_TOKEN)->json($method, '/api/livsipro'.$uri, $data);
    }

    // ---------------------------------------------------------------- курьер

    public function test_courier_is_quoted_from_settings_without_asking_a_carrier(): void
    {
        $this->enableCourier();

        $this->courierQuote()->assertOk()
            ->assertJsonPath('price', 25000)->assertJsonPath('courier', true)
            ->assertJsonPath('note', 'Привезём сегодня или завтра.');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/calculator'));

        PromoRule::where('type', 'free_shipping')->update(['threshold' => 1]);
        $this->courierQuote()->assertOk()->assertJsonPath('price', 0);
    }

    public function test_courier_is_refused_outside_stavropol_and_while_switched_off(): void
    {
        $this->courierQuote()->assertUnprocessable();

        $this->enableCourier();
        $this->courierQuote(['delivery_city' => 'Москва', 'delivery_region' => 'Москва'])->assertUnprocessable();
        $this->courierQuote(['delivery_city' => ' г. ставрополь '])->assertOk();
    }

    public function test_courier_order_keeps_the_address_and_is_not_sent_to_a_carrier(): void
    {
        $this->enableCourier();
        $token = $this->courierQuote()->assertOk()->json('token');

        $this->checkout(['delivery_quote_token' => $token])->assertRedirect('https://example.test/pay');

        $order = Order::firstOrFail();
        $this->assertSame(DeliverySelection::STAVROPOL_COURIER, $order->delivery_method);
        $this->assertSame('ул. Ленина, 10, кв. 5', $order->delivery_address);
        $this->assertSame(25000, $order->delivery_price);
        $this->assertNull($order->delivery_point_id);

        $payment = $order->payments()->create(['gateway' => 'yookassa', 'idempotence_key' => (string) Str::uuid(),
            'status' => Payment::STATUS_SUCCEEDED, 'amount' => $order->total, 'external_id' => 'pay_courier']);
        app(PaymentService::class)->markPaid($payment);

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertFalse((bool) $order->shipment_automatic);
        $this->assertNull($order->shipment_state);
    }

    public function test_pickup_quote_cannot_pay_for_a_courier(): void
    {
        $this->enableCourier();
        $token = $this->pointQuote('43')->assertOk()->json('token');

        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_livsipro_shows_courier_address_and_marks_hand_over_and_delivery(): void
    {
        $order = $this->paidOrder(DeliverySelection::STAVROPOL_COURIER, ['delivery_address' => 'ул. Ленина, 10, кв. 5']);
        app(FulfillmentService::class)->set($order, Fulfillment::ASSEMBLED, 'Собрал тест', OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        $this->livsipro('GET', '/orders/'.$order->number)->assertOk()
            ->assertJsonPath('order.courier.address', 'ул. Ленина, 10, кв. 5')
            ->assertJsonPath('order.courier.phone', '+79001112233')
            ->assertJsonPath('order.actions.courier_shipped', true)
            ->assertJsonPath('order.actions.courier_delivered', false);

        $this->livsipro('POST', '/orders/'.$order->number.'/delivered', ['by' => 'Мария'])->assertUnprocessable();
        $this->livsipro('POST', '/orders/'.$order->number.'/shipped', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.fulfillment.code', Fulfillment::SHIPPED)
            ->assertJsonPath('order.actions.courier_delivered', true);
        $this->livsipro('POST', '/orders/'.$order->number.'/delivered', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.fulfillment.code', Fulfillment::DELIVERED);
    }

    public function test_livsipro_does_not_show_contacts_or_mark_delivery_for_carrier_orders(): void
    {
        $order = $this->paidOrder(DeliverySelection::CDEK_POINT);
        app(FulfillmentService::class)->set($order, Fulfillment::SHIPPED, 'Передал тест', OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        $response = $this->livsipro('GET', '/orders/'.$order->number)->assertOk()->assertJsonPath('order.courier', null);
        $this->assertStringNotContainsString('79001112233', $response->getContent());
        $this->livsipro('POST', '/orders/'.$order->number.'/delivered', ['by' => 'Мария'])->assertUnprocessable();
    }

    public function test_courier_settings_are_saved_from_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(Settings::class)
            ->fillForm(['stavropol_courier_enabled' => true, 'stavropol_courier_price' => '250', 'stavropol_courier_note' => 'Сегодня до 21:00'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue(Setting::get('stavropol_courier_enabled'));
        $this->assertSame(25000, Setting::get('stavropol_courier_price'));
        $this->assertSame('Сегодня до 21:00', Setting::get('stavropol_courier_note'));
    }

    // ------------------------------------------------- дорогой или долгий пункт

    public function test_far_point_gets_a_hint_with_usual_price_and_days(): void
    {
        $this->pointQuote('42')->assertOk()->assertJsonPath('price', 91859)->assertJsonPath(
            'note', 'Яндекс везёт в этот пункт дороже и дольше обычного. В другие пункты Яндекс в этом городе — от '.Money::rub(15853).', 3 дня.');

        $this->pointQuote('43')->assertOk()->assertJsonPath('note', null);
    }

    /** На карте пункты всей области: дальний пункт может числиться за посёлком, где других пунктов нет. */
    public function test_far_point_listed_under_a_suburb_is_compared_with_the_buyers_city(): void
    {
        $this->fakeCarrier(cities: ['42' => 'Михайловск']);

        $this->pointQuote('42')->assertOk()->assertJsonPath('price', 91859)->assertJsonPath(
            'note', 'Яндекс везёт в этот пункт дороже и дольше обычного. В другие пункты Яндекс в этом городе — от '.Money::rub(15853).', 3 дня.');
    }

    public function test_failed_usual_price_is_not_remembered_for_long(): void
    {
        $this->fakeCarrier(['42' => [918.59, 9]]);
        $this->pointQuote('42')->assertOk()->assertJsonPath('note', null);

        $this->fakeCarrier();
        $this->travel(11)->minutes();
        $this->pointQuote('42')->assertOk()->assertJsonPath('note', fn (?string $note): bool => str_contains((string) $note, 'дороже'));
    }

    public function test_free_delivery_to_a_far_point_mentions_only_the_days(): void
    {
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 1]);

        $this->pointQuote('42')->assertOk()->assertJsonPath('price', 0)->assertJsonPath(
            'note', 'Яндекс везёт в этот пункт дольше обычного: 9 дней. В другие пункты Яндекс в этом городе — от 3 дня.');
    }

    public function test_usual_price_is_asked_once_per_city_and_parcel(): void
    {
        $this->pointQuote('42')->assertOk();
        $this->pointQuote('42')->assertOk();

        // Первый расчёт: сам пункт и два других; второй — только сам пункт.
        $calculations = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/calculator'));
        $this->assertCount(4, $calculations);
    }

    // ------------------------------------------------------ пункт и промокод

    public function test_refused_promo_code_keeps_the_chosen_pickup_point(): void
    {
        $this->withCookie(CartService::COOKIE, $this->cartToken)->post(route('checkout.promo'), [
            'promo_code' => 'NOSUCHCODE', 'delivery_method' => DeliverySelection::PICKUP_POINT,
            'delivery_point_method' => DeliverySelection::YANDEX_POINT, 'delivery_point_id' => '43',
            'delivery_point_hint' => 'Ставрополь, пункт 43', 'delivery_city' => 'Ставрополь',
        ])->assertRedirect(route('checkout.index'))->assertSessionHasErrors('promo_code');

        $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))->assertOk()
            ->assertSee('name="delivery_point_id" value="43"', false)
            ->assertSee('name="delivery_point_method" value="'.DeliverySelection::YANDEX_POINT.'"', false)
            ->assertSee('value="Ставрополь, пункт 43"', false);
    }

    // ------------------------------------------------------------- помощники

    private function paidOrder(string $method, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => $method, 'delivery_city' => 'Ставрополь',
            'delivery_quote' => $method === DeliverySelection::STAVROPOL_COURIER
                ? ['courier' => true, 'provider' => 'courier', 'carrier_cost' => 19900]
                : ['provider' => 'cdek', 'carrier_cost' => 35000],
            'items_total' => 178000, 'total' => 178000, 'status' => Order::STATUS_PAID, 'paid_at' => now()->subHour(),
            'consent_at' => now(), 'access_token' => Str::random(48),
        ], $overrides));
        $variant = ProductVariant::firstOrFail();
        OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
            'title' => 'Мультипенка', 'option_label' => '200 мл', 'unit_price' => 89000, 'quantity' => 2, 'total' => 178000]);

        return $order->fresh();
    }
}
