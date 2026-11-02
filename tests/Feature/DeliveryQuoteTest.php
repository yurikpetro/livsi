<?php

namespace Tests\Feature;

use App\Mail\OrderPlaced;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Models\Setting;
use App\Payments\ReceiptBuilder;
use App\Services\CartService;
use App\Services\DeliveryQuoteService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryQuoteTest extends TestCase
{
    use RefreshDatabase;

    private string $cartToken;

    private ProductVariant $variant;

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
        config(['livsi.apiship.token' => 'test-token', 'livsi.apiship.active_providers' => ['cdek', 'yataxi', 'ozonlog'],
            'livsi.yookassa.shop_id' => '123', 'livsi.yookassa.secret_key' => 'test_secret']);
        Http::preventStrayRequests();
        Mail::fake();
        $this->fakeApi();
        $this->cartToken = Str::random(32);
        Cart::create(['token' => $this->cartToken, 'last_activity_at' => now()]);
        $this->withSession(['quote_test' => true]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();
        $this->variant->update(['dimensions_verified' => true, 'weight_g' => 250, 'length_mm' => 61, 'width_mm' => 45, 'height_mm' => 181]);
        $this->withCookie(CartService::COOKIE, $this->cartToken)
            ->post(route('cart.add'), ['variant_id' => $this->variant->id, 'qty' => 2]);
    }

    private function fakeApi(?array $tariffs = null, bool $fail = false, string $provider = 'cdek'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [[
                'id' => '42', 'providerKey' => $provider, 'countryCode' => 'RU', 'city' => 'Москва',
                'region' => 'Москва', 'address' => 'Москва, улица 1', 'availableOperation' => 2, 'enabled' => true,
            ]]]),
            '*/calculator' => Http::response(['deliveryToPoint' => [[
                'providerKey' => $provider, 'tariffs' => $tariffs ?? [$this->tariff()],
            ]]], $fail ? 503 : 200),
            '*/payments' => Http::response(['id' => 'quote_payment', 'status' => 'pending',
                'confirmation' => ['confirmation_url' => 'https://example.test/pay']]),
            '*/payments/quote_payment' => Http::response(['id' => 'quote_payment', 'status' => 'pending']),
            '*/ozondel/check-client' => Http::response(['canBeDelivered' => true]),
        ]);
    }

    private function tariff(array $overrides = []): array
    {
        return array_replace(['tariffId' => 7, 'tariffName' => 'Посылка', 'deliveryCost' => 345.67,
            'feesIncluded' => true, 'pickupTypes' => [1], 'deliveryTypes' => [2], 'pointIds' => [42],
            'calendarDaysMin' => 2, 'calendarDaysMax' => 4], $overrides);
    }

    private function destination(array $overrides = []): array
    {
        return array_replace(['delivery_city' => 'Москва', 'delivery_region' => 'Москва',
            'delivery_point_method' => 'cdek_point', 'delivery_point_id' => '42'], $overrides);
    }

    private function quote(array $overrides = []): TestResponse
    {
        return $this->withCookie(CartService::COOKIE, $this->cartToken)
            ->postJson(route('delivery.quote'), $this->destination($overrides));
    }

    private function checkout(array $overrides = []): TestResponse
    {
        return $this->withCookie(CartService::COOKIE, $this->cartToken)
            ->post(route('checkout.store'), array_merge($this->destination(), [
                'name' => 'Мария', 'phone' => '+79000000000', 'email' => 'maria@example.test',
                'consent' => '1', 'delivery_method' => 'pickup_point',
            ], $overrides));
    }

    public function test_calculation_uses_verified_dimensions_quantity_packaging_and_selected_point(): void
    {
        $this->quote()->assertOk()->assertJsonPath('price', 34567)->assertJsonPath('days_max', 4);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/calculator')
            && $r['weight'] === 590 && $r['length'] === 9 && $r['width'] === 12 && $r['height'] === 21
            && $r['pointOutId'] === 42 && $r['providerKeys'] === ['cdek'] && $r['includeFees'] === true && $r['codCost'] === 0);
    }

    public function test_delivery_is_in_order_payment_receipt_page_and_email(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->checkout(['delivery_quote_token' => $token, 'delivery_price' => 1])->assertRedirect('https://example.test/pay');
        $order = Order::firstOrFail();
        $this->assertSame(34567, $order->delivery_price);
        $this->assertSame($order->items_total - $order->discount_total + 34567, $order->total);
        $this->assertSame(34567, $order->delivery_quote['carrier_cost']);
        $this->assertSame($order->total, (new ReceiptBuilder($order))->total());
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payments')
            && $r['amount']['value'] === number_format($order->total / 100, 2, '.', '')
            && collect($r['receipt']['items'])->contains(fn ($line): bool => $line['description'] === 'Доставка' && $line['amount']['value'] === '345.67'));
        $this->get($order->accessUrl())->assertOk()->assertSee('Доставка рассчитана и включена');
        $this->assertStringContainsString('Доставка рассчитана и включена', (new OrderPlaced($order))->render());
    }

    public function test_free_delivery_keeps_carrier_cost_but_does_not_charge_customer(): void
    {
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 1]);
        $token = $this->quote()->assertOk()->assertJsonPath('price', 0)->json('token');
        $this->checkout(['delivery_quote_token' => $token])->assertRedirect('https://example.test/pay');
        $order = Order::firstOrFail();
        $this->assertSame(0, $order->delivery_price);
        $this->assertSame(34567, $order->delivery_quote['carrier_cost']);
        $this->assertSame($order->items_total - $order->discount_total, $order->total);
    }

    public function test_checkout_shows_free_delivery_before_selection_at_the_active_promo_threshold(): void
    {
        $subtotal = Cart::where('token', $this->cartToken)->firstOrFail()->subtotal();
        foreach ([$subtotal - 1, $subtotal] as $threshold) {
            PromoRule::where('type', 'free_shipping')->update(['threshold' => $threshold]);
            $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))->assertOk()
                ->assertSee('aria-live="polite">Бесплатно</span>', false)
                ->assertSee('Доставка бесплатная для вашего заказа. Выберите пункт выдачи, чтобы уточнить срок доставки.');
        }
        PromoRule::where('type', 'free_shipping')->update(['threshold' => $subtotal + 1]);
        $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))->assertOk()
            ->assertSee('aria-live="polite">Не рассчитана</span>', false)->assertDontSee('Доставка бесплатная для вашего заказа.');
        PromoRule::where('type', 'free_shipping')->update(['threshold' => 1, 'is_active' => false]);
        $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))->assertOk()
            ->assertSee('aria-live="polite">Не рассчитана</span>', false)->assertDontSee('Доставка бесплатная для вашего заказа.');
        Http::assertNothingSent();
    }

    public function test_yandex_quote_can_be_paid_without_a_region(): void
    {
        $this->fakeApi(provider: 'yataxi');
        $token = $this->quote(['delivery_point_method' => 'yandex_point', 'delivery_region' => ''])
            ->assertOk()->json('token');
        $this->checkout(['delivery_quote_token' => $token, 'delivery_point_method' => 'yandex_point', 'delivery_region' => ''])
            ->assertRedirect('https://example.test/pay');
        $this->assertSame('yataxi', Order::firstOrFail()->delivery_quote['provider']);
    }

    public function test_dropoff_calculation_uses_configured_origin_point(): void
    {
        Setting::put('delivery_pickup_type', 2);
        Setting::put('delivery_origin_points', ['cdek' => '21']);
        $this->fakeApi([$this->tariff(['pickupTypes' => [2]])]);
        $this->quote()->assertOk();
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/calculator')
            && $r['pickupTypes'] === [2] && $r['pointInId'] === 21);
    }

    public function test_seed_dimensions_and_zero_dimensions_cannot_be_used(): void
    {
        $this->variant->update(['dimensions_verified' => false]);
        $this->quote()->assertUnprocessable()->assertJsonValidationErrors('delivery_quote');
        $this->variant->update(['dimensions_verified' => true, 'weight_g' => 0]);
        $this->quote()->assertUnprocessable()->assertJsonValidationErrors('delivery_quote');
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/calculator'));
    }

    public function test_gift_weight_is_included_even_though_gift_has_no_price(): void
    {
        $cart = Cart::where('token', $this->cartToken)->firstOrFail();
        PromoRule::where('type', 'gift')->update(['threshold' => 1]);
        $gift = ProductVariant::where('id', '!=', $this->variant->id)->firstOrFail();
        $gift->update(['dimensions_verified' => true, 'weight_g' => 100, 'length_mm' => 50, 'width_mm' => 30, 'height_mm' => 100]);
        $cart->items()->create(['product_variant_id' => $gift->id, 'qty' => 1, 'is_gift' => true]);
        $parcel = app(DeliveryQuoteService::class)->parcel($cart->fresh()->load('items.variant'));
        $this->assertSame(690, $parcel['weight']);
        $this->assertSame(15, $parcel['width']);
    }

    public function test_explicit_test_mode_uses_unverified_dimensions_without_marking_them_real(): void
    {
        Setting::put('delivery_calculation_test', true);
        $this->variant->update(['dimensions_verified' => false]);
        $token = $this->quote()->assertOk()->assertJsonPath('test', true)->json('token');
        $this->checkout(['delivery_quote_token' => $token])->assertRedirect('https://example.test/pay');
        $this->assertTrue(Order::firstOrFail()->delivery_quote['test']);
        $this->assertFalse($this->variant->fresh()->dimensions_verified);
    }

    public function test_unverified_dimensions_cannot_be_used_with_a_live_payment_key(): void
    {
        Setting::put('delivery_calculation_test', true);
        config(['livsi.yookassa.secret_key' => 'live_secret']);
        $this->variant->update(['dimensions_verified' => false]);
        $this->quote()->assertUnprocessable()->assertJsonValidationErrors('delivery_quote');
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/calculator'));
    }

    public function test_turning_off_test_mode_invalidates_previously_calculated_quotes(): void
    {
        Setting::put('delivery_calculation_test', true);
        $token = $this->quote()->assertOk()->json('token');
        Setting::put('delivery_calculation_test', false);
        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_zero_values_are_rejected_even_in_test_mode(): void
    {
        Setting::put('delivery_calculation_test', true);
        $this->variant->update(['dimensions_verified' => false, 'width_mm' => 0]);
        $this->quote()->assertUnprocessable()->assertJsonValidationErrors('delivery_quote');
    }

    public function test_checkout_labels_temporary_dimensions_as_a_test(): void
    {
        Setting::put('delivery_calculation_test', true);
        $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))
            ->assertOk()->assertSee('Пробный расчёт: используются временные размеры товаров и упаковки.');
    }

    public function test_quote_cannot_be_tampered_with_or_used_for_another_destination(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->checkout(['delivery_quote_token' => $token.'bad'])->assertSessionHasErrors('delivery_quote');
        $this->checkout(['delivery_quote_token' => $token, 'delivery_point_id' => '43'])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expired_quote_is_rejected(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->travel(11)->minutes();
        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changing_quantity_invalidates_the_quote(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        Cart::where('token', $this->cartToken)->firstOrFail()->items()->update(['qty' => 1]);
        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changing_dimensions_invalidates_the_quote(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->variant->update(['width_mm' => 90]);
        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
    }

    public function test_unavailable_point_or_incompatible_tariff_is_not_quoted(): void
    {
        $this->quote(['delivery_point_id' => '99'])->assertUnprocessable();
        $this->fakeApi([$this->tariff(['pointIds' => [99]])]);
        $this->quote()->assertUnprocessable();
        $this->fakeApi([$this->tariff(['feesIncluded' => false])]);
        $this->quote()->assertUnprocessable();
        $this->fakeApi([$this->tariff(['pickupTypes' => [2]])]);
        $this->quote()->assertUnprocessable();
    }

    public function test_cheapest_compatible_tariff_is_used(): void
    {
        $this->fakeApi([$this->tariff(['deliveryCost' => 2, 'pointIds' => [99]]),
            $this->tariff(['deliveryCost' => 500]), $this->tariff(['deliveryCost' => 123.45])]);
        $this->quote()->assertOk()->assertJsonPath('price', 12345);
    }

    public function test_api_failure_blocks_checkout_even_with_the_removed_manual_flag(): void
    {
        $this->fakeApi(fail: true);
        $this->quote()->assertUnprocessable();
        $this->checkout()->assertSessionHasErrors('delivery_quote');
        $this->checkout(['delivery_manual' => '1'])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $this->variant->quota->fresh()->reserved);
        $this->assertFalse(Cart::where('token', $this->cartToken)->firstOrFail()->isEmpty());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/payments'));
    }

    public function test_removed_manual_flag_cannot_remove_delivery_from_a_valid_quote(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->checkout(['delivery_quote_token' => $token, 'delivery_manual' => '1'])
            ->assertRedirect('https://example.test/pay');
        $order = Order::firstOrFail();
        $this->assertSame(34567, $order->delivery_price);
        $this->assertNotNull($order->delivery_quote);
        $this->assertSame($order->items_total - $order->discount_total + 34567, $order->total);
    }

    public function test_ozon_does_not_return_the_placeholder_tariff_or_claim_phone_was_checked(): void
    {
        $this->quote(['delivery_point_method' => 'ozon_point'])->assertUnprocessable()
            ->assertJsonPath('errors.delivery_quote.0', 'Расчёт доставки Ozon временно недоступен. Выберите пункт выдачи другой службы.');
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/calculator'));
    }

    public function test_missing_packaging_or_dropoff_point_cannot_produce_a_quote(): void
    {
        Setting::put('delivery_packaging_weight_g', null);
        $this->quote()->assertUnprocessable();
        Setting::put('delivery_packaging_weight_g', 90);
        Setting::put('delivery_pickup_type', 2);
        $this->quote()->assertUnprocessable();
    }

    public function test_ozon_uses_default_dropoff_method_and_adds_known_insurance_once(): void
    {
        config(['livsi.apiship.active_providers' => ['cdek', 'yataxi', 'ozondel'], 'livsi.apiship.ozon_connection_id' => 40706]);
        foreach ([null, false] as $included) {
            $this->fakeApi([$this->tariff(['deliveryCost' => 156, 'feesIncluded' => $included,
                'insuranceFee' => 17, 'cashServiceFee' => null, 'pickupTypes' => [2]])], provider: 'ozondel');
            $token = $this->quote(['delivery_point_method' => 'ozon_point'])->assertOk()
                ->assertJsonPath('price', 17300)->assertJsonPath('days_max', 4)->json('token');
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/calculator')
                && $request['providerKeys'] === ['ozondel'] && $request['pickupTypes'] === [2]
                && ! isset($request['pointInId']) && $request['includeFees'] === false
                && $request['codCost'] === 0 && $request['assessedCost'] > 0);
        }
        $this->checkout(['delivery_point_method' => 'ozon_point', 'delivery_quote_token' => $token])
            ->assertRedirect('https://example.test/pay');
        $order = Order::firstOrFail();
        $this->assertSame('ozondel', $order->delivery_quote['provider']);
        $this->assertSame(17300, $order->delivery_price);
        $this->assertSame(17300, $order->delivery_quote['carrier_cost']);
        $this->assertSame($order->items_total - $order->discount_total + 17300, $order->total);
        $this->assertSame($order->total, (new ReceiptBuilder($order))->total());
    }

    public function test_ozon_does_not_double_charge_fees_confirmed_as_included(): void
    {
        config(['livsi.apiship.active_providers' => ['ozondel']]);
        $this->fakeApi([$this->tariff(['deliveryCost' => 173, 'insuranceFee' => 17, 'pickupTypes' => [2]])], provider: 'ozondel');
        $this->quote(['delivery_point_method' => 'ozon_point'])->assertOk()->assertJsonPath('price', 17300);
    }

    public function test_ozon_rejects_unknown_or_invalid_fees_and_chooses_by_full_cost(): void
    {
        config(['livsi.apiship.active_providers' => ['ozondel']]);
        foreach ([null, -1, 'unknown'] as $insurance) {
            $this->fakeApi([$this->tariff(['feesIncluded' => null, 'insuranceFee' => $insurance,
                'pickupTypes' => [2]])], provider: 'ozondel');
            $this->quote(['delivery_point_method' => 'ozon_point'])->assertUnprocessable();
        }
        $this->fakeApi([
            $this->tariff(['deliveryCost' => 100, 'feesIncluded' => null, 'insuranceFee' => 100, 'pickupTypes' => [2]]),
            $this->tariff(['deliveryCost' => 150.25, 'feesIncluded' => false, 'insuranceFee' => 0, 'cashServiceFee' => 1.5, 'pickupTypes' => [2]]),
        ], provider: 'ozondel');
        $this->quote(['delivery_point_method' => 'ozon_point'])->assertOk()->assertJsonPath('price', 15175);
        $this->fakeApi([$this->tariff(['feesIncluded' => null, 'insuranceFee' => 17,
            'cashServiceFee' => -1, 'pickupTypes' => [2]])], provider: 'ozondel');
        $this->quote(['delivery_point_method' => 'ozon_point'])->assertUnprocessable();
    }

    public function test_changing_ozon_connection_invalidates_previous_quotes(): void
    {
        config(['livsi.apiship.ozon_connection_id' => 40706]);
        $token = $this->quote()->assertOk()->json('token');
        config(['livsi.apiship.ozon_connection_id' => 40707]);
        $this->checkout(['delivery_quote_token' => $token])->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_is_bound_to_session_and_cart(): void
    {
        $cart = Cart::where('token', $this->cartToken)->firstOrFail();
        $service = app(DeliveryQuoteService::class);
        $quote = $service->calculate($cart, $this->destination());
        $token = $service->token($quote, 'original-session');
        $this->expectException(ValidationException::class);
        $service->accept($token, $cart, $this->destination(), 'other-session');
    }

    public function test_checkout_contains_calculator_without_exposing_api_token(): void
    {
        $this->withCookie(CartService::COOKIE, $this->cartToken)->get(route('checkout.index'))
            ->assertOk()->assertSee('data-delivery-quote-url', false)->assertDontSee('test-token')
            ->assertDontSee('name="delivery_manual"', false)->assertDontSee('Согласовать доставку с менеджером');
    }

    public function test_disabling_calculation_cannot_bypass_the_delivery_charge(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        Setting::put('delivery_calculation_enabled', false);
        $this->checkout(['delivery_quote_token' => $token, 'delivery_manual' => '1'])->assertSessionHasErrors('delivery_quote');
        $this->checkout()->assertSessionHasErrors('delivery_quote');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_pickup_quote_cannot_be_reused_for_courier_delivery(): void
    {
        $token = $this->quote()->assertOk()->json('token');
        $this->checkout(['delivery_quote_token' => $token, 'delivery_method' => 'stavropol_courier',
            'delivery_city' => 'Ставрополь', 'delivery_address' => 'Ленина, 1'])->assertSessionHasErrors('delivery_method');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
