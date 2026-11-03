<?php

namespace Tests\Feature;

use App\Delivery\Carrier;
use App\Delivery\DeliveryGateway;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Jobs\CreateOrderShipment;
use App\Jobs\SendPaymentTelegram;
use App\Jobs\SendShipmentTelegram;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\User;
use App\Monitoring\PaymentNotifications;
use App\Monitoring\ShipmentNotifications;
use App\Services\PaymentService;
use App\Services\ShipmentAutomation;
use App\Services\ShipmentService;
use App\Support\Fulfillment;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AutomaticShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Mail::fake();
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake([SendPaymentTelegram::class, SendShipmentTelegram::class]);
        Http::preventStrayRequests();
        config(['livsi.apiship.token' => 'fake-apiship-token', 'livsi.apiship.api_url' => 'https://apiship.example.test/v1',
            'livsi.apiship.active_providers' => ['ozondel', 'cdek', 'yataxi'], 'livsi.apiship.ozon_connection_id' => '40706',
            'livsi.shipments.connection' => 'database', 'livsi.telegram.token' => 'fake-telegram-token',
            'livsi.telegram.api_url' => 'https://telegram.example.test', 'livsi.telegram.chat_id' => '-1004404612976',
            'livsi.telegram.topics.shipments' => 113, 'livsi.telegram.tries' => 1]);
        Setting::put('delivery_origin_address', 'Ставрополь, Складская улица, 18');
        Setting::put('delivery_sender_phone', '+79991112233');
        Setting::put('delivery_padding_mm', 10);
        Setting::put('delivery_packaging_weight_g', 100);
        Setting::put('delivery_pickup_type', 1);
        SellerProfile::current()->update(['email' => 'sender@example.test']);
        $this->variant = ProductVariant::firstOrFail();
        $this->variant->update(['dimensions_verified' => true, 'weight_g' => 200,
            'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 150]);
    }

    private function order(bool $automatic = false): Order
    {
        $order = Order::create(['number' => 'AUTO-'.(Order::count() + 1), 'customer_name' => 'Private Buyer',
            'customer_phone' => '+79990000000', 'customer_email' => 'private-buyer@example.test',
            'items_total' => 30000, 'total' => 40800, 'delivery_price' => 10800,
            'status' => $automatic ? Order::STATUS_PAID : Order::STATUS_AWAITING_PAYMENT,
            'paid_at' => $automatic ? now() : null, 'consent_at' => now(), 'access_token' => Str::random(48),
            'shipment_automatic' => $automatic, 'shipment_state' => $automatic ? 'queued' : null,
            'delivery_method' => 'ozon_point', 'delivery_country_code' => 'RU', 'delivery_city' => 'Ставрополь',
            'delivery_point_id' => '2381537', 'delivery_point_address' => 'Private pickup address',
            'delivery_quote' => ['test' => true, 'provider' => 'ozondel', 'tariff_id' => 147,
                'carrier_cost' => 10800, 'tariff_name' => 'Тариф',
                'parcel' => ['weight' => 300, 'length' => 7, 'width' => 7, 'height' => 17]]]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'sku' => $this->variant->sku,
            'title' => 'Мульти-пенка', 'unit_price' => 30000, 'quantity' => 1, 'total' => 30000]);

        return $order;
    }

    private function payment(Order $order): Payment
    {
        return $order->payments()->create(['status' => Payment::STATUS_SUCCEEDED, 'amount' => $order->total,
            'external_id' => (string) Str::uuid(), 'idempotence_key' => (string) Str::uuid(), 'payload' => ['test' => true]]);
    }

    private function runCreation(Order $order): CreateOrderShipment
    {
        $job = (new CreateOrderShipment($order->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    private function sendNotification(Order $order): SendShipmentTelegram
    {
        $job = (new SendShipmentTelegram($order->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }

    /** @return array<string, mixed> */
    private function carrierStatus(Order $order, string $key = 'uploaded', string $name = 'Передано перевозчику'): array
    {
        return ['orderInfo' => ['orderId' => '123456', 'clientNumber' => $order->number,
            'providerKey' => 'ozondel', 'providerNumber' => 'TRACK-123'], 'status' => ['key' => $key, 'name' => $name]];
    }

    public function test_confirmed_payment_creates_shipment_in_worker_without_admin_and_notifies_topic_113(): void
    {
        Queue::swap($this->realQueue);
        $this->mock(PaymentNotifications::class)->shouldReceive('queue');
        $order = $this->order();
        Http::fake(['*/orders' => Http::response(['orderId' => 123456]),
            'telegram.example.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        app(PaymentService::class)->sync($this->payment($order));
        $this->assertTrue($order->fresh()->shipment_automatic);
        $this->assertSame('queued', $order->fresh()->shipment_state);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
        $this->assertGuest();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame($order->fresh()->shipment_event_key, $order->fresh()->shipment_notified_key);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'telegram.example.test')
            && $request['chat_id'] === '-1004404612976' && $request['message_thread_id'] === 113
            && str_contains($request['text'], $order->number) && str_contains($request['text'], '123456')
            && ! str_contains($request['text'], $order->customer_name)
            && ! str_contains($request['text'], $order->customer_phone)
            && ! str_contains($request['text'], $order->customer_email)
            && ! str_contains($request['text'], $order->access_token)
            && ! str_contains($request['text'], $order->delivery_point_address));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders')
            && $request['cost']['codCost'] === 0 && $request['sender']['phone'] === '+79991112233');
    }

    public function test_duplicate_payment_does_not_enqueue_another_shipment_or_sell_stock_twice(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $stale = $payment->fresh('order');
        $sold = $this->variant->quota->sold;
        app(PaymentService::class)->sync($payment);
        app(PaymentService::class)->sync($stale);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($sold + 1, $this->variant->quota->fresh()->sold);
        Http::assertNothingSent();
    }

    public function test_rollback_does_not_queue_shipping_or_keep_automatic_marker(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        DB::beginTransaction();
        app(PaymentService::class)->sync($payment);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertFalse($order->fresh()->shipment_automatic);
        $this->assertFalse($order->fresh()->isPaid());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queue_outage_preserves_paid_order_and_scheduler_restores_shipping(): void
    {
        $order = $this->order();
        config(['livsi.shipments.connection' => 'unavailable']);
        app(PaymentService::class)->sync($this->payment($order));
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame('queued', $order->fresh()->shipment_state);
        $this->assertDatabaseCount('jobs', 0);
        config(['livsi.shipments.connection' => 'database']);
        $this->artisan('shipments:process')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_existing_paid_orders_are_never_backfilled_or_notified(): void
    {
        Queue::fake();
        $old = $this->order();
        $old->update(['status' => Order::STATUS_PAID, 'paid_at' => now()->subDays(10)]);
        $new = $this->order(true);
        $this->runCreation($old);
        $this->artisan('shipments:process')->assertSuccessful();
        Queue::assertPushed(CreateOrderShipment::class, fn ($job): bool => $job->orderId === $new->id);
        Queue::assertPushed(CreateOrderShipment::class, 1);
        Queue::assertNotPushed(SendShipmentTelegram::class);
        $this->assertNull($old->fresh()->shipment_state);
        Http::assertNothingSent();
    }

    public function test_automatic_entrypoint_rejects_orders_without_new_payment_marker(): void
    {
        $order = $this->order();
        $order->update(['status' => Order::STATUS_PAID]);
        $this->expectException(ValidationException::class);
        app(ShipmentService::class)->createAutomatically($order);
    }

    public function test_timeout_only_reconciles_by_client_number_and_never_reposts(): void
    {
        $order = $this->order(true);
        $posts = 0;
        Http::fake(['*/orders' => function () use (&$posts): never {
            $posts++;
            throw new ConnectionException('timeout with private token');
        }, '*/orders/status*' => Http::response($this->carrierStatus($order))]);
        $this->runCreation($order)->assertReleased(60);
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        $this->runCreation($order);
        $this->assertSame(1, $posts);
        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        $this->assertNull($order->fresh()->shipment_error);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['clientNumber'] === $order->number);
    }

    public function test_unconfirmed_lookup_stays_unknown_without_a_new_creation_request(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'creating', 'shipment_requested_at' => now()->subMinutes(10)]);
        Http::fake(['*/orders/status*' => Http::response([], 404)]);
        $this->runCreation($order);
        $this->runCreation($order);
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_invalid_dimensions_fail_in_background_without_interrupting_payment(): void
    {
        $order = $this->order(true);
        $this->variant->update(['dimensions_verified' => false]);
        $this->runCreation($order);
        $this->assertTrue($order->fresh()->isPaid());
        $this->assertSame('failed', $order->fresh()->shipment_state);
        $this->assertStringContainsString($this->variant->sku, $order->fresh()->shipment_error);
        Queue::assertPushed(SendShipmentTelegram::class, 1);
        Http::assertNothingSent();
    }

    public function test_definite_rejection_is_not_reposted_by_duplicate_worker(): void
    {
        $order = $this->order(true);
        Http::fake(['*/orders' => Http::response(['message' => 'private recipient data'], 422)]);
        $this->runCreation($order);
        $this->runCreation($order);
        $this->assertSame('failed', $order->fresh()->shipment_state);
        $this->assertStringNotContainsString('private', $order->fresh()->shipment_error);
        Http::assertSentCount(1);
    }

    public function test_retry_button_only_requeues_failed_automatic_shipping_without_confirmation(): void
    {
        Queue::fake();
        $order = $this->order(true);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(ListOrders::class)->assertActionHidden(TestAction::make('retryShipment')->table($order));
        $order->update(['shipment_state' => 'failed', 'shipment_error' => 'Исправьте настройки']);
        Livewire::test(ListOrders::class)->callAction(TestAction::make('retryShipment')->table($order))
            ->assertNotified('Отправление поставлено в очередь');
        $this->assertSame('queued', $order->fresh()->shipment_state);
        Queue::assertPushed(CreateOrderShipment::class, 1);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_retry_automatic_shipping(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'failed']);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->expectException(HttpException::class);
        app(ShipmentAutomation::class)->retry($order);
    }

    public function test_unchanged_status_is_silent_and_changed_statuses_are_reported_once(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'created', 'apiship_order_id' => '123456']);
        Http::fake(['*/orders/status*' => Http::sequence()
            ->push($this->carrierStatus($order))->push($this->carrierStatus($order))
            ->push($this->carrierStatus($order, 'onWay', 'В пути'))->push($this->carrierStatus($order, 'delivered', 'Доставлено')),
            'telegram.example.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        for ($index = 0; $index < 4; $index++) {
            $this->runCreation($order);
            $this->sendNotification($order);
        }
        $this->assertSame('delivered', $order->fresh()->shipment_provider_status);
        $this->runCreation($order);
        $this->sendNotification($order);
        $this->assertCount(3, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'telegram.example.test')));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders'));
        $this->assertSame($order->fresh()->shipment_event_key, $order->fresh()->shipment_notified_key);
    }

    public function test_missing_topic_never_falls_back_to_general_chat(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'failed', 'shipment_error' => 'Не хватает данных']);
        app(ShipmentNotifications::class)->queue($order);
        config(['livsi.telegram.topics.shipments' => null]);
        $this->sendNotification($order)->assertReleased(60);
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->shipment_notified_key);
    }

    public function test_notification_outage_keeps_event_pending_and_does_not_create_another_shipment(): void
    {
        $order = $this->order(true);
        Http::fake(['*/orders' => Http::response(['orderId' => 123456]),
            'telegram.example.test/*' => Http::sequence()->push([], 503)->push(['ok' => true, 'result' => ['message_id' => 2]])]);
        $this->runCreation($order);
        $this->sendNotification($order)->assertReleased(60);
        $this->assertNull($order->fresh()->shipment_notified_key);
        $this->sendNotification($order);
        $this->assertNotNull($order->fresh()->shipment_notified_at);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/orders')));
    }

    public function test_scheduler_ignores_terminal_shipments_and_dry_run_never_dispatches(): void
    {
        Queue::fake();
        $terminal = $this->order(true);
        $terminal->update(['shipment_state' => 'created', 'apiship_order_id' => '123456', 'shipment_provider_status' => 'delivered']);
        $new = $this->order(true);
        $this->artisan('shipments:process', ['--dry-run' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('shipments:process')->assertSuccessful();
        Queue::assertPushed(CreateOrderShipment::class, fn ($job): bool => $job->orderId === $new->id);
        Queue::assertPushed(CreateOrderShipment::class, 1);
    }

    public function test_cancelled_order_cannot_be_created_by_queued_job(): void
    {
        $order = $this->order(true);
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->runCreation($order);
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->apiship_order_id);
    }

    public function test_shipment_message_escapes_provider_text_and_contains_only_operational_data(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'created', 'apiship_order_id' => '123456',
            'shipment_status_name' => '<script>status</script>', 'shipment_tracking_number' => '<b>TRACK</b>']);
        $html = app(ShipmentNotifications::class)->toHtml($order);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        foreach ([$order->customer_name, $order->customer_phone, $order->customer_email, $order->access_token, $order->delivery_point_address] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_terminal_status_and_pending_notification_are_saved_together(): void
    {
        Queue::fake();
        $order = $this->order(true);
        $order->update(['shipment_state' => 'created', 'apiship_order_id' => '123456',
            'shipment_provider_status' => 'delivered', 'shipment_status_name' => 'Доставлено']);
        $this->assertNotNull($order->fresh()->shipment_event_key);
        $this->assertNull($order->fresh()->shipment_notified_key);
        $this->artisan('shipments:process')->assertSuccessful();
        Queue::assertNotPushed(CreateOrderShipment::class);
        Queue::assertPushed(SendShipmentTelegram::class, 1);
    }

    public function test_notification_queue_outage_can_be_recovered_without_waiting_for_unique_lock_expiry(): void
    {
        Queue::swap($this->realQueue);
        $order = $this->order(true);
        $order->update(['shipment_state' => 'created', 'apiship_order_id' => '123456',
            'shipment_provider_status' => 'delivered']);
        config(['livsi.telegram.payments_connection' => 'unavailable']);
        app(ShipmentNotifications::class)->queue($order);
        $this->assertDatabaseCount('jobs', 0);
        config(['livsi.telegram.payments_connection' => 'database']);
        $this->artisan('shipments:process')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_failed_worker_preserves_uncertainty_and_never_repeats_creation(): void
    {
        $order = $this->order(true);
        $order->update(['shipment_state' => 'creating', 'shipment_requested_at' => now()->subMinutes(10)]);
        (new CreateOrderShipment($order->id))->failed(new \RuntimeException('worker timeout'));
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        Http::fake(['*/orders/status*' => Http::response($this->carrierStatus($order))]);
        $this->runCreation($order);
        $this->assertSame('123456', $order->fresh()->apiship_order_id);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_real_webhook_queues_shipping_once_and_never_calls_carrier_inside_payment_request(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $payment->update(['status' => Payment::STATUS_PENDING]);
        Setting::put('vat_not_payer', true);
        config(['livsi.yookassa.webhook_ips' => ['127.0.0.1'], 'livsi.yookassa.shop_id' => 'test',
            'livsi.yookassa.secret_key' => 'test_key']);
        Http::fake(['api.yookassa.ru/v3/payments/*' => Http::response([
            'id' => $payment->external_id, 'status' => 'succeeded', 'test' => true])]);
        $this->postJson('/webhooks/yookassa', ['object' => ['id' => $payment->external_id]])->assertOk();
        $this->postJson('/webhooks/yookassa', ['object' => ['id' => $payment->external_id]])->assertOk();
        $this->assertTrue($order->fresh()->shipment_automatic);
        $this->assertSame('queued', $order->fresh()->shipment_state);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'apiship'));
    }

    private function directCarrier(): MockInterface
    {
        $carrier = \Mockery::mock(Carrier::class);
        $carrier->shouldReceive('key')->andReturn('ozondel');
        $carrier->shouldReceive('isConfigured')->andReturnTrue();
        $this->mock(DeliveryGateway::class)->shouldReceive('carrier')->with('ozondel')->andReturn($carrier);

        return $carrier;
    }

    public function test_new_payment_uses_existing_direct_integration_without_an_admin_session(): void
    {
        $order = $this->order();
        $order->update(['delivery_quote' => array_merge($order->delivery_quote, ['direct' => true])]);
        $carrier = $this->directCarrier();
        $carrier->shouldReceive('createShipment')->once()->withArgs(fn (Order $sent, array $sender): bool => $sent->id === $order->id && $sender['phone'] === '+79991112233')
            ->andReturn(['external_id' => 'DIRECT-1', 'tracking' => 'TRACK-1', 'status_code' => 'created',
                'status_name' => 'Создано', 'data' => ['number' => 'HUMAN-1']]);
        $carrier->shouldReceive('shipmentInfo')->once()->andReturn(['status_code' => 'in_transit', 'status_name' => 'В пути',
            'tracking' => 'TRACK-1', 'fulfillment' => Fulfillment::SHIPPED, 'data' => []]);
        app(PaymentService::class)->sync($this->payment($order));
        $this->assertGuest();
        $this->runCreation($order);
        $this->assertSame('DIRECT-1', $order->fresh()->shipment_external_id);
        $this->assertTrue($order->fresh()->hasShipment());
        $this->assertSame('Ozon №HUMAN-1', $order->fresh()->shipmentReference());
        $this->runCreation($order);
        $this->assertSame(Fulfillment::SHIPPED, $order->fresh()->fulfillment_status);
        $this->assertDatabaseHas('order_fulfillment_events', ['order_id' => $order->id, 'status' => Fulfillment::SHIPPED]);
        Http::assertNothingSent();
    }

    public function test_unknown_direct_creation_reconciles_without_creating_twice(): void
    {
        $order = $this->order(true);
        $order->update(['delivery_quote' => array_merge($order->delivery_quote, ['direct' => true])]);
        $carrier = $this->directCarrier();
        $carrier->shouldReceive('createShipment')->once()->andThrow(new \RuntimeException('Lost response'));
        $carrier->shouldReceive('findShipment')->once()->andReturn(['external_id' => 'DIRECT-2',
            'status_code' => 'created', 'status_name' => 'Создано', 'data' => []]);
        $carrier->shouldReceive('shipmentInfo')->once()->andReturn(['status_code' => 'created', 'data' => []]);
        $this->runCreation($order)->assertReleased(60);
        $this->assertSame('unknown', $order->fresh()->shipment_state);
        $this->runCreation($order);
        $this->assertSame('DIRECT-2', $order->fresh()->shipment_external_id);
        $this->assertNull($order->fresh()->apiship_order_id);
        Http::assertNothingSent();
    }

    public function test_scheduler_tracks_direct_shipments_and_stops_after_delivery_or_cancellation(): void
    {
        Queue::fake();
        $active = $this->order(true);
        $active->update(['shipment_state' => 'created', 'shipment_provider' => 'ozondel', 'shipment_external_id' => 'DIRECT-A']);
        $delivered = $this->order(true);
        $delivered->update(['shipment_state' => 'created', 'shipment_provider' => 'ozondel', 'shipment_external_id' => 'DIRECT-B',
            'fulfillment_status' => Fulfillment::DELIVERED]);
        $cancelled = $this->order(true);
        $cancelled->update(['shipment_state' => 'cancelled', 'shipment_provider' => 'ozondel', 'shipment_external_id' => 'DIRECT-C']);
        $this->artisan('shipments:process')->assertSuccessful();
        Queue::assertPushed(CreateOrderShipment::class, 1);
        Queue::assertPushed(CreateOrderShipment::class, fn ($job): bool => $job->orderId === $active->id);
        $this->assertTrue(ShipmentAutomation::isTerminal($delivered));
        $this->assertTrue(ShipmentAutomation::isTerminal($cancelled));
        $this->runCreation($delivered);
        $this->runCreation($cancelled);
        Http::assertNothingSent();
    }

    public function test_direct_carrier_status_code_changes_notify_once_with_shipment_reference(): void
    {
        $order = $this->order(true);
        Http::fake(['telegram.example.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $order->update(['shipment_state' => 'created', 'shipment_provider' => 'ozondel', 'shipment_external_id' => 'DIRECT-N',
            'shipment_status_code' => 'created', 'shipment_status_name' => 'Принято', 'shipment_data' => ['number' => 'PUBLIC-N']]);
        app(ShipmentNotifications::class)->queue($order);
        $this->sendNotification($order);
        $before = $order->fresh()->shipment_event_key;
        $order->refresh()->update(['shipment_status_code' => 'accepted']);
        $this->assertNotSame($before, $order->fresh()->shipment_event_key);
        app(ShipmentNotifications::class)->queue($order);
        $this->sendNotification($order);
        $this->sendNotification($order);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['message_thread_id'] === 113
            && str_contains($request['text'], 'Ozon №PUBLIC-N') && ! str_contains($request['text'], 'ApiShip:'));
    }
}
