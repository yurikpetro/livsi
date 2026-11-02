<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\DeliverySelection;
use App\Services\OzonDeliveryService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OzonClientCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $cartToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Setting::put('vat_rate_default', '20');
        config([
            'livsi.apiship.token' => 'private-apiship-token',
            'livsi.apiship.active_providers' => ['cdek', 'yataxi', 'ozondel'],
            'livsi.apiship.ozon_connection_id' => 40706,
            'livsi.yookassa.shop_id' => '123', 'livsi.yookassa.secret_key' => 'test_secret',
        ]);
        Mail::fake();
        $this->fakeApi();
        $this->withSession(['ozon_check_test' => true]);
        $this->withCredentials();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->cartToken = Str::random(32);
        Cart::create(['token' => $this->cartToken, 'last_activity_at' => now()]);
        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();
        $this->withUnencryptedCookie(CartService::COOKIE, $this->cartToken)
            ->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => 1]);
    }

    private function fakeApi(mixed $result = true, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/ozondel/check-client' => Http::response(['canBeDelivered' => $result], $status),
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '42', 'providerKey' => 'ozondel', 'countryCode' => 'RU',
                'city' => 'Москва', 'region' => 'Москва', 'address' => 'Москва, улица 1',
                'availableOperation' => 2, 'enabled' => true,
            ]], 'meta' => ['total' => 1]]),
            '*/payments' => Http::response(['id' => 'ozon_payment', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay']]),
        ]);
    }

    private function checkPhone(string $phone = '8 (900) 000-00-00'): TestResponse
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $this->cartToken)
            ->postJson(route('delivery.ozon.check-client'), ['phone' => $phone]);
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $this->cartToken)
            ->post(route('checkout.store'), array_replace([
                'name' => 'Мария', 'phone' => '+7 900 000-00-00', 'email' => 'maria@example.test', 'consent' => '1',
                'delivery_method' => DeliverySelection::PICKUP_POINT, 'delivery_city' => 'Москва', 'delivery_region' => 'Москва',
                'delivery_point_method' => DeliverySelection::OZON_POINT, 'delivery_point_id' => '42', 'delivery_manual' => '1',
            ], $overrides));
    }

    public function test_check_normalizes_phone_and_keeps_credentials_private(): void
    {
        $this->checkPhone()->assertOk()->assertExactJson(['can_be_delivered' => true])
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('private-apiship-token');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ozondel/check-client')
            && $request['phoneNumber'] === '+79000000000' && $request['providerConnectId'] === 40706
            && $request->hasHeader('Authorization', 'private-apiship-token'));
        $this->assertSame('ozondel', DeliverySelection::providerKey(DeliverySelection::OZON_POINT));
    }

    public function test_denial_remains_false_and_is_cached_for_the_session(): void
    {
        $this->fakeApi(false);
        $this->checkPhone()->assertOk()->assertExactJson(['can_be_delivered' => false]);
        $this->checkPhone('+7 900 000-00-00')->assertOk()->assertExactJson(['can_be_delivered' => false]);
        Http::assertSentCount(1);
    }

    public function test_service_checks_new_numbers_sessions_and_expired_results(): void
    {
        $ozon = app(OzonDeliveryService::class);
        $this->assertTrue($ozon->canDeliver('+79000000000', 'one'));
        $this->assertTrue($ozon->canDeliver('+79000000000', 'one'));
        $this->assertTrue($ozon->canDeliver('+79000000001', 'one'));
        $this->assertTrue($ozon->canDeliver('+79000000000', 'two'));
        Http::assertSentCount(3);
        $this->travel(6)->minutes();
        $this->assertTrue($ozon->canDeliver('+79000000000', 'one'));
        Http::assertSentCount(4);
    }

    public function test_empty_cart_and_disabled_provider_do_not_call_apiship(): void
    {
        $this->app->forgetInstance(CartService::class);
        $this->withUnencryptedCookie(CartService::COOKIE, Str::random(32))
            ->postJson(route('delivery.ozon.check-client'), ['phone' => '+79000000000'])->assertUnprocessable();
        config(['livsi.apiship.active_providers' => ['cdek', 'ozonlog']]);
        $this->checkPhone()->assertServiceUnavailable()->assertJsonMissingPath('can_be_delivered');
        Http::assertNothingSent();
    }

    public function test_invalid_phone_is_rejected_without_an_external_request(): void
    {
        foreach (['123', '+1 234 567 8901', '+7abc9000000000', '+70000000000', str_repeat('9', 31)] as $phone) {
            $this->checkPhone($phone)->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        Http::assertNothingSent();
    }

    public function test_malformed_results_and_outages_are_unknown_and_not_cached_as_denial(): void
    {
        foreach (['false', 1, null] as $result) {
            $this->fakeApi($result);
            $this->checkPhone()->assertServiceUnavailable()->assertJsonMissingPath('can_be_delivered');
        }
        $this->fakeApi(null, 503);
        $this->checkPhone()->assertServiceUnavailable()->assertJsonMissingPath('can_be_delivered');
        $this->fakeApi(true);
        $this->checkPhone()->assertOk()->assertJsonPath('can_be_delivered', true);
    }

    public function test_denial_blocks_generic_and_direct_checkout_despite_manual_agreement(): void
    {
        $this->fakeApi(false);
        foreach ([DeliverySelection::PICKUP_POINT, DeliverySelection::OZON_POINT] as $method) {
            $this->checkout(['delivery_method' => $method])->assertSessionHasErrors('delivery_point_id');
        }
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_outage_blocks_ozon_without_creating_an_order_or_payment(): void
    {
        $this->fakeApi(null, 503);
        $this->checkout()->assertSessionHasErrors('delivery_point_id');
        $this->assertDatabaseCount('orders', 0);
        Http::assertSentCount(1);
    }

    public function test_changed_phone_cannot_reuse_a_previous_approval(): void
    {
        $this->checkPhone()->assertOk();
        $this->fakeApi(false);
        $this->checkout(['phone' => '+79000000001'])->assertSessionHasErrors('delivery_point_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_approved_phone_cannot_allow_checkout_without_a_delivery_quote(): void
    {
        $this->checkPhone()->assertOk();
        $this->checkout()->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_renders_guard_and_points_use_ozondel(): void
    {
        $this->withUnencryptedCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))
            ->assertOk()->assertSee('data-ozon-check-url=', false)->assertSee('data-ozon-check-retry', false)
            ->assertDontSee('private-apiship-token');
        $this->getJson(route('delivery.points', ['method' => 'ozon_point', 'city' => 'Москва']))
            ->assertOk()->assertJsonPath('points.0.provider', 'ozondel');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'providerKey%3Dozondel'));
    }

    public function test_phone_approval_does_not_bypass_delivery_calculation(): void
    {
        Setting::put('delivery_calculation_enabled', true);
        $this->checkout(['delivery_manual' => null])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_phone_limit_does_not_block_other_delivery_endpoints(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->checkPhone()->assertOk();
        }
        $this->checkPhone()->assertTooManyRequests()->assertHeader('Retry-After');
        $this->getJson(route('delivery.points', ['method' => 'ozon_point', 'city' => 'Москва']))->assertOk();
        Http::assertSentCount(2);
    }
}
