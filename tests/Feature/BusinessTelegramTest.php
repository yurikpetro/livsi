<?php

namespace Tests\Feature;

use App\Auth\SocialProfile;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Jobs\SendBusinessTelegram;
use App\Jobs\SendPaymentTelegram;
use App\Models\LeadRequest;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\StockQuota;
use App\Models\User;
use App\Monitoring\BusinessReport;
use App\Services\PaymentService;
use App\Services\SocialAuthService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessTelegramTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('logs/business-telegram-test.log');
        @unlink($this->logPath);
        config([
            'livsi.telegram.token' => 'fake-telegram-token',
            'livsi.telegram.chat_id' => '-1004404612976',
            'livsi.telegram.topics.leads' => 13,
            'livsi.telegram.topics.stock' => 15,
            'livsi.telegram.topics.users' => 17,
            'livsi.telegram.topics.issues' => 19,
            'livsi.telegram.payments_connection' => 'database',
            'livsi.telegram.tries' => 1,
            'livsi.telegram.fallback_channel' => 'business_test_file',
            'logging.channels.business_test_file' => ['driver' => 'single', 'path' => $this->logPath],
            'logging.channels.stack.channels' => ['single'],
        ]);
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('business_test_file');
        @unlink($this->logPath);
        parent::tearDown();
    }

    public function test_both_lead_forms_queue_messages_without_personal_contacts(): void
    {
        Mail::fake();
        Queue::fake([SendBusinessTelegram::class]);

        $this->post(route('contract.store'), ['name' => 'Иван', 'contact' => '@private', 'consent' => '1', 'product_category' => 'face'])->assertRedirect();
        $this->post(route('partners.store'), ['name' => 'Ольга', 'contact' => '+79990001122', 'consent' => '1', 'city' => 'Ставрополь'])->assertRedirect();

        Queue::assertPushed(SendBusinessTelegram::class, 2);
        foreach (LeadRequest::all() as $lead) {
            $message = app(BusinessReport::class)->render('lead', $lead->id);
            $this->assertStringContainsString('Новая заявка', $message);
            $this->assertStringContainsString('/admin/lead-requests/'.$lead->id.'/edit', $message);
            $this->assertStringNotContainsString($lead->contact, $message);
            $this->assertStringNotContainsString($lead->name, $message);
        }
    }

    public function test_lead_worker_sends_to_topic_13(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->post(route('contract.store'), ['name' => 'Иван', 'contact' => '@ivan', 'consent' => '1'])->assertRedirect();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['message_thread_id'] === 13 && str_contains($request['text'], 'Контрактное производство'));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_only_new_social_account_registration_is_announced(): void
    {
        Queue::fake([SendBusinessTelegram::class]);
        $profile = new SocialProfile('yandex', '123', 'buyer@example.com', 'Покупатель', '+79001112233');

        $user = app(SocialAuthService::class)->login($profile);
        app(SocialAuthService::class)->login($profile);
        User::factory()->create(['is_admin' => true]);

        Queue::assertPushed(SendBusinessTelegram::class, 1);
        $message = app(BusinessReport::class)->render('user', $user->id, 'yandex');
        $this->assertStringContainsString('Способ: yandex', $message);
        $this->assertStringNotContainsString('buyer@example.com', $message);
        $this->assertStringNotContainsString('+79001112233', $message);
    }

    public function test_stock_alerts_only_on_low_and_empty_crossings_and_rearms_after_refill(): void
    {
        $this->seed(CatalogSeeder::class);
        Queue::fake([SendBusinessTelegram::class]);
        $quota = StockQuota::firstOrFail();

        $quota->update(['allocated' => 5]);
        $quota->update(['allocated' => 4]);
        $quota->update(['allocated' => 0]);
        $quota->update(['allocated' => 25]);
        $quota->update(['allocated' => 5]);

        Queue::assertPushed(SendBusinessTelegram::class, 3);
        $message = app(BusinessReport::class)->render('stock_low', $quota->id);
        $this->assertStringContainsString('Остаток заканчивается', $message);
        $this->assertStringContainsString('Доступно: 5 шт.', $message);
    }

    public function test_replenished_stock_suppresses_a_stale_queued_alert(): void
    {
        $this->seed(CatalogSeeder::class);
        Queue::fake([SendBusinessTelegram::class]);
        $quota = StockQuota::firstOrFail();

        $quota->update(['allocated' => 5]);
        $quota->update(['allocated' => 25]);

        Queue::assertPushed(SendBusinessTelegram::class, 1);
        $this->assertNull(app(BusinessReport::class)->render('stock_low', $quota->id));
    }

    public function test_reserving_stock_triggers_low_alert_without_duplicate_on_sale(): void
    {
        $this->seed(CatalogSeeder::class);
        Mail::fake();
        Queue::fake([SendBusinessTelegram::class, SendPaymentTelegram::class]);
        $quota = StockQuota::firstOrFail();
        $order = $this->order();
        $order->items()->create([
            'product_variant_id' => $quota->product_variant_id,
            'sku' => 'TEST', 'title' => 'Товар', 'unit_price' => 4500,
            'quantity' => 20, 'total' => 90000,
        ]);
        $payment = $order->payments()->create([
            'gateway' => 'yookassa', 'external_id' => 'pay-test',
            'idempotence_key' => (string) Str::uuid(),
            'status' => 'succeeded', 'amount' => 90000,
        ]);

        $quota->increment('reserved', 20);
        app(PaymentService::class)->markPaid($payment);

        Queue::assertPushed(SendBusinessTelegram::class, 1);
        $this->assertSame(5, $quota->fresh()->available());
        $this->assertSame(20, $quota->fresh()->sold);
    }

    public function test_direct_drop_to_zero_sends_only_empty_alert(): void
    {
        $this->seed(CatalogSeeder::class);
        Queue::fake([SendBusinessTelegram::class]);
        $quota = StockQuota::firstOrFail();

        $quota->update(['allocated' => 0]);

        Queue::assertPushed(SendBusinessTelegram::class, fn (SendBusinessTelegram $job) => $job->kind === 'stock_empty');
        Queue::assertPushed(SendBusinessTelegram::class, 1);
    }

    public function test_rolled_back_stock_change_does_not_queue_alert(): void
    {
        $this->seed(CatalogSeeder::class);
        Queue::fake([SendBusinessTelegram::class]);
        $quota = StockQuota::firstOrFail();

        DB::beginTransaction();
        $quota->update(['allocated' => 5]);
        DB::rollBack();

        Queue::assertNotPushed(SendBusinessTelegram::class);
    }

    public function test_admin_records_refund_request_without_marking_money_refunded(): void
    {
        Queue::fake([SendBusinessTelegram::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order(Order::STATUS_PAID);

        Livewire::actingAs($admin)->test(EditOrder::class, ['record' => $order->id])
            ->callAction('reportIssue', ['kind' => OrderIssue::REFUND_REQUEST, 'details' => 'Покупатель попросил возврат']);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(OrderIssue::REFUND_REQUEST, $order->issues()->sole()->kind);
        Queue::assertPushed(SendBusinessTelegram::class, 1);
        $message = app(BusinessReport::class)->render('order_issue', $order->issues()->sole()->id);
        $this->assertStringContainsString('Запрос на возврат', $message);
        $this->assertStringContainsString('требует проверки', $message);
    }

    public function test_delivery_problem_is_recorded_in_same_topic(): void
    {
        Queue::fake([SendBusinessTelegram::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order();

        Livewire::actingAs($admin)->test(EditOrder::class, ['record' => $order->id])
            ->callAction('reportIssue', ['kind' => OrderIssue::DELIVERY_PROBLEM, 'details' => 'ПВЗ не принял заказ']);

        $issue = $order->issues()->sole();
        $this->assertSame('ПВЗ не принял заказ', $issue->details);
        Queue::assertPushed(SendBusinessTelegram::class, 1);
        $this->assertStringContainsString('Проблема доставки', app(BusinessReport::class)->render('order_issue', $issue->id));
    }

    public function test_order_status_cannot_be_changed_to_refunded_from_admin_form(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order(Order::STATUS_PAID);

        Livewire::actingAs($admin)->test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['status' => Order::STATUS_REFUNDED])
            ->call('save');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_refund_request_cannot_be_recorded_for_unpaid_order(): void
    {
        Queue::fake([SendBusinessTelegram::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->order();

        Livewire::actingAs($admin)->test(EditOrder::class, ['record' => $order->id])
            ->callAction('reportIssue', ['kind' => OrderIssue::REFUND_REQUEST, 'details' => 'Ошибочный запрос'])
            ->assertHasErrors();

        $this->assertDatabaseCount('order_issues', 0);
        Queue::assertNotPushed(SendBusinessTelegram::class);
    }

    public function test_telegram_failure_is_logged_without_breaking_lead_submission(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad thread'], 400)]);

        $this->post(route('contract.store'), ['name' => 'Иван', 'contact' => '@ivan', 'consent' => '1'])->assertRedirect();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame(1, LeadRequest::count());
        $this->assertStringContainsString('Оповещение в Телеграм не ушло.', file_get_contents($this->logPath));
        $this->assertStringContainsString('bad thread', file_get_contents($this->logPath));
    }

    public function test_missing_topic_does_not_send_into_general_chat(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['livsi.telegram.topics.leads' => null]);

        $this->post(route('contract.store'), ['name' => 'Иван', 'contact' => '@ivan', 'consent' => '1'])->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(1, LeadRequest::count());
        $this->assertStringContainsString('Не задан топик', file_get_contents($this->logPath));
    }

    public function test_queue_failure_is_logged_and_does_not_break_lead_form(): void
    {
        Mail::fake();
        config(['livsi.telegram.payments_connection' => 'missing-queue']);

        $this->post(route('contract.store'), ['name' => 'Иван', 'contact' => '@ivan', 'consent' => '1'])->assertRedirect();

        $this->assertSame(1, LeadRequest::count());
        $this->assertStringContainsString('missing-queue', file_get_contents($this->logPath));
    }

    private function order(string $status = Order::STATUS_AWAITING_PAYMENT): Order
    {
        return Order::create([
            'number' => 'LV-'.Str::random(8),
            'status' => $status,
            'customer_name' => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'buyer@example.com',
            'items_total' => 90000,
            'total' => 90000,
            'consent_at' => now(),
            'access_token' => Str::random(48),
        ]);
    }
}
