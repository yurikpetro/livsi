<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpireOrderReservesTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        config([
            'livsi.yookassa.shop_id' => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
            'livsi.order_reserve_minutes' => 60,
        ]);
        Setting::put('vat_rate_default', '20');
        $this->variant = ProductVariant::whereHas('quota', fn ($query) => $query->where('allocated', '>', 5))->firstOrFail();
    }

    private function createOrder(): Order
    {
        $token = Str::random(32);
        Http::fake(['*/payments' => Http::response([
            'id' => 'pay_1',
            'status' => Payment::STATUS_PENDING,
            'confirmation' => ['confirmation_url' => 'https://yoomoney.test/pay'],
        ])]);

        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->post(route('cart.add'), ['variant_id' => $this->variant->id, 'qty' => 2]);

        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->post(route('checkout.store'), [
                'name' => 'Мария',
                'phone' => '+7 900 000-00-00',
                'email' => 'maria@example.test',
                'consent' => '1',
            ]);

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_provider_cancellation_releases_the_reserve_once(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake([
            '*/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'canceled']),
        ]);

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_CANCELED, $order->lastPayment()->status);
        $this->assertSame(0, $this->variant->quota->fresh()->reserved);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));

        $this->artisan('orders:expire-reserves')->assertExitCode(0);
        $this->assertSame(1, $this->variant->quota->movements()->where('type', 'release')->count());
    }

    public function test_pending_payment_keeps_the_reserve_until_yookassa_expires_it(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake(['*/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'pending'])]);

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved, 'Резерв не создан вторым заказом');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
    }

    public function test_waiting_for_capture_can_be_cancelled_before_releasing_reserve(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake([
            '*/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'waiting_for_capture']),
            '*/payments/pay_1/cancel' => Http::response(['id' => 'pay_1', 'status' => 'canceled']),
        ]);

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $this->variant->quota->fresh()->reserved);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/payments/pay_1/cancel'));
    }

    public function test_provider_failure_keeps_the_reserve(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake([
            '*/payments/pay_1' => Http::response([], 503),
            '*/payments/pay_1/cancel' => Http::response([], 503),
        ]);

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved, 'Резерв второго заказа снят при обработке первого');
    }

    public function test_late_success_is_reconciled_as_paid(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake(['*/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'succeeded'])]);

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(0, $this->variant->quota->fresh()->reserved);
        $this->assertSame(2, $this->variant->quota->fresh()->sold);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
    }

    public function test_fresh_order_is_not_touched(): void
    {
        $order = $this->createOrder();
        Http::fake();

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_contact_provider_or_change_inventory(): void
    {
        $order = $this->createOrder();
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake();

        $this->artisan('orders:expire-reserves', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_unknown_provider_payment_id_is_kept_for_manual_reconciliation(): void
    {
        $order = $this->createOrder();
        $order->lastPayment()->update(['external_id' => null]);
        $order->forceFill(['created_at' => now()->subMinutes(61)])->save();
        Http::fake();

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved);
        Http::assertNothingSent();
    }

    public function test_legacy_failed_checkout_cannot_release_a_later_reservation(): void
    {
        $token = Str::random(32);
        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->post(route('cart.add'), ['variant_id' => $this->variant->id, 'qty' => 2]);
        Http::fake(['*/payments' => Http::response(['type' => 'error'], 400)]);
        $this->withUnencryptedCookie(CartService::COOKIE, $token)
            ->post(route('checkout.store'), [
                'name' => 'Мария',
                'phone' => '+7 900 000-00-00',
                'email' => 'maria@example.test',
                'consent' => '1',
            ]);

        $failed = Order::query()->firstOrFail();
        $this->assertSame(Order::STATUS_CANCELLED, $failed->status);

        $this->variant->quota->increment('reserved', 2);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved, 'Новый резерв не создан');
        $failed->update(['status' => Order::STATUS_AWAITING_PAYMENT]);
        $failed->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('orders:expire-reserves')->assertExitCode(0);

        $this->assertSame(Order::STATUS_CANCELLED, $failed->fresh()->status);
        $this->assertSame(2, $this->variant->quota->fresh()->reserved, 'Новый резерв снят при обработке старого заказа');
    }
}
