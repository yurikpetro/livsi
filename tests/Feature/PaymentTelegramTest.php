<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentTelegram;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockQuotaMovement;
use App\Monitoring\PaymentReport;
use App\Monitoring\TelegramFailure;
use App\Services\PaymentService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentTelegramTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('logs/payment-telegram-test.log');
        @unlink($this->logPath);
        config([
            'livsi.telegram.token' => 'fake-telegram-token',
            'livsi.telegram.chat_id' => '-1004404612976',
            'livsi.telegram.topics.payments' => 2,
            'livsi.telegram.payments_connection' => 'database',
            'livsi.telegram.tries' => 1,
            'livsi.telegram.fallback_channel' => 'telegram_test_file',
            'logging.channels.telegram_test_file' => ['driver' => 'single', 'path' => $this->logPath],
            'logging.channels.stack.channels' => ['single'],
        ]);
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('telegram_test_file');
        @unlink($this->logPath);
        parent::tearDown();
    }

    public function test_webhook_queues_payment_and_worker_sends_to_payments_topic(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $payment = $this->payment(Payment::STATUS_PENDING);
        Setting::put('vat_rate_default', '22');
        config(['livsi.yookassa.webhook_ips' => ['127.0.0.1'], 'livsi.yookassa.shop_id' => 'test', 'livsi.yookassa.secret_key' => 'test_key']);
        Http::fake([
            'api.yookassa.ru/v3/payments/*' => Http::response(['id' => $payment->external_id, 'status' => 'succeeded', 'test' => true, 'payment_method' => ['type' => 'sbp']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->postJson('/webhooks/yookassa', ['object' => ['id' => $payment->external_id]])->assertOk();

        $this->assertTrue($payment->order->fresh()->isPaid());
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && $request['chat_id'] === '-1004404612976' && $request['message_thread_id'] === 2
            && str_contains($request['text'], 'Тестовая оплата')
            && str_contains($request['text'], '900')
            && str_contains($request['text'], 'СБП')
            && str_contains($request['text'], '/admin/orders/'.$payment->order_id.'/edit')
            && ! str_contains($request['text'], $payment->order->access_token)
            && ! str_contains($request['text'], 'buyer@example.com'));
        $this->assertNotNull($payment->fresh()->telegram_notified_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_repeated_payment_with_stale_order_does_not_notify_or_sell_twice(): void
    {
        Mail::fake();
        Queue::fake([SendPaymentTelegram::class]);
        $this->seed(CatalogSeeder::class);
        $payment = $this->payment();
        $variant = ProductVariant::whereHas('quota')->firstOrFail();
        $payment->order->items()->create(['product_variant_id' => $variant->id, 'sku' => 'TEST', 'title' => 'Товар', 'unit_price' => 90000, 'quantity' => 1, 'total' => 90000]);
        $stale = $payment->fresh('order');
        $before = $variant->quota->sold;

        app(PaymentService::class)->sync($payment);
        app(PaymentService::class)->sync($stale);

        Queue::assertPushed(SendPaymentTelegram::class, 1);
        $this->assertSame($before + 1, $variant->quota->fresh()->sold);
        $this->assertSame(1, StockQuotaMovement::where('type', 'sell')->count());
    }

    public function test_rollback_does_not_enqueue_notification(): void
    {
        Mail::fake();
        Queue::fake([SendPaymentTelegram::class]);
        $payment = $this->payment();
        DB::beginTransaction();
        app(PaymentService::class)->sync($payment);
        Queue::assertNotPushed(SendPaymentTelegram::class);
        DB::rollBack();

        Queue::assertNotPushed(SendPaymentTelegram::class);
        $this->assertFalse($payment->order->fresh()->isPaid());
    }

    public function test_queue_failure_is_logged_and_payment_stays_paid(): void
    {
        Mail::fake();
        $payment = $this->payment();
        config(['livsi.telegram.payments_connection' => 'missing-queue']);

        app(PaymentService::class)->sync($payment);

        $this->assertTrue($payment->order->fresh()->isPaid());
        $this->assertStringContainsString('dispatch', file_get_contents($this->logPath));
        $this->assertStringContainsString('missing-queue', file_get_contents($this->logPath));
    }

    #[DataProvider('failedResponses')]
    public function test_delivery_failure_is_logged_and_retried_without_throwing(int $status, array $body): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);
        $payment = $this->paidPayment();
        $job = (new SendPaymentTelegram($payment->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(60);
        $this->assertNull($payment->fresh()->telegram_notified_at);
        $this->assertTrue($payment->order->fresh()->isPaid());
        $this->assertStringContainsString('Оповещение в Телеграм не ушло.', file_get_contents($this->logPath));
        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return ['server' => [503, []], 'rejected' => [400, ['ok' => false, 'description' => 'chat not found']], 'rate limit' => [429, ['ok' => false]], 'invalid success' => [200, ['ok' => false]]];
    }

    public function test_network_error_logs_without_secret_and_stops_after_last_attempt(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('timeout /botfake-telegram-token/sendMessage')]);
        $payment = $this->paidPayment();
        $job = new SendPaymentTelegram($payment->id);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        $log = file_get_contents($this->logPath);
        $this->assertStringContainsString('timeout', $log);
        $this->assertStringNotContainsString('fake-telegram-token', $log);
        $this->assertNull($payment->fresh()->telegram_notified_at);
    }

    public function test_redelivered_job_does_not_send_confirmed_message_twice(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $payment = $this->paidPayment();

        app()->call([new SendPaymentTelegram($payment->id), 'handle']);
        app()->call([new SendPaymentTelegram($payment->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertNotNull($payment->fresh()->telegram_notified_at);
    }

    public function test_no_message_for_pending_or_cancelled_payment(): void
    {
        Mail::fake();
        Queue::fake([SendPaymentTelegram::class]);
        foreach ([Payment::STATUS_PENDING, Payment::STATUS_CANCELED] as $status) {
            app(PaymentService::class)->sync($this->payment($status));
        }

        Queue::assertNotPushed(SendPaymentTelegram::class);
    }

    public function test_missing_topic_does_not_send_to_general_or_error_topic(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['livsi.telegram.topics.payments' => null]);
        $payment = $this->paidPayment();

        app()->call([new SendPaymentTelegram($payment->id), 'handle']);

        Http::assertNothingSent();
        $this->assertStringContainsString('TELEGRAM_TOPIC_PAYMENTS', file_get_contents($this->logPath));
    }

    public function test_error_logger_cannot_recurse_when_fallback_points_to_telegram(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['livsi.telegram.fallback_channel' => 'telegram']);
        Log::shouldReceive('channel')->once()->with('daily')->andReturnSelf();
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('forgetChannel')->with('telegram_test_file');

        TelegramFailure::record(new RuntimeException('failed'));

        Http::assertNothingSent();
    }

    public function test_notification_is_optional_when_bot_is_not_configured(): void
    {
        Mail::fake();
        Queue::fake([SendPaymentTelegram::class]);
        config(['livsi.telegram.token' => null]);
        $payment = $this->payment();

        app(PaymentService::class)->sync($payment);

        Queue::assertNotPushed(SendPaymentTelegram::class);
        $this->assertTrue($payment->order->fresh()->isPaid());
    }

    public function test_unavailable_log_does_not_throw_or_contact_telegram(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Log::shouldReceive('channel')->once()->andThrow(new RuntimeException('disk unavailable'));
        Log::shouldReceive('forgetChannel')->with('telegram_test_file');

        TelegramFailure::record(new RuntimeException('network unavailable'));

        Http::assertNothingSent();
    }

    public function test_check_command_targets_payments_without_claiming_a_real_payment(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'test_bot']]),
            '*/getChat' => Http::response(['ok' => true, 'result' => ['is_forum' => true]]),
            '*/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->artisan('monitor:check', ['--topic' => 'payments'])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['message_thread_id'] === 2
            && str_contains($request['text'], 'реальной оплаты не было'));
    }

    public function test_payment_report_escapes_external_fields_and_uses_payment_test_flag(): void
    {
        $payment = $this->paidPayment();
        $payment->update(['external_id' => '<script>bad</script>', 'payload' => ['test' => false]]);

        $text = app(PaymentReport::class)->toHtml($payment->fresh());

        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $text);
        $this->assertStringNotContainsString('Тестовая оплата', $text);
        $this->assertStringNotContainsString($payment->order->access_token, $text);
    }

    private function paidPayment(): Payment
    {
        $payment = $this->payment();
        $payment->order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        return $payment;
    }

    private function payment(string $status = Payment::STATUS_SUCCEEDED): Payment
    {
        $order = Order::create(['number' => 'LV-'.Str::random(8), 'customer_name' => 'Иван', 'customer_phone' => '+79001112233', 'customer_email' => 'buyer@example.com', 'items_total' => 90000, 'total' => 90000, 'consent_at' => now(), 'access_token' => Str::random(48)]);

        return $order->payments()->create(['gateway' => 'yookassa', 'external_id' => 'test-'.Str::uuid(), 'idempotence_key' => Str::uuid(), 'status' => $status, 'amount' => 90000, 'payment_method' => 'sbp', 'payload' => ['test' => true], 'paid_at' => $status === 'succeeded' ? now() : null]);
    }
}
