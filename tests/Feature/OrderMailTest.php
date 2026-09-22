<?php

namespace Tests\Feature;

use App\Mail\OrderCancelled;
use App\Mail\OrderPaid;
use App\Mail\OrderPaidManagerNotice;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\PaymentService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Транзакционные письма по заказу.
 *
 * Три повода: оформление, оплата и отмена. Главное, что здесь проверяется, —
 * письмо об оплате уходит ровно один раз, хотя вебхук провайдера приходит
 * повторно, и что сбой почты не ломает оплату.
 */
class OrderMailTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        config([
            'livsi.yookassa.shop_id'    => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
        ]);

        Setting::put('vat_rate_default', '20');
        Setting::put('manager_email', 'manager@livsi.shop');

        $this->token = Str::random(32);

        Mail::fake();
    }

    // ─────────────────────────────────── вспомогательное

    private function order(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'number'         => 'L-' . Str::upper(Str::random(6)),
            'customer_name'  => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'buyer@example.com',
            'items_total'    => 90000,
            'total'          => 90000,
            'consent_at'     => now(),
            'access_token'   => Str::random(48),
        ], $overrides));

        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'sku'                => $variant->sku ?? 'SKU',
            'title'              => $variant->product->title,
            'option_label'       => $variant->storefrontLabel(),
            'unit_price'         => 45000,
            'quantity'           => 2,
            'total'              => 90000,
        ]);

        return $order->fresh('items');
    }

    private function payment(Order $order, string $status = Payment::STATUS_SUCCEEDED): Payment
    {
        return $order->payments()->create([
            'gateway'         => 'yookassa',
            'idempotence_key' => (string) Str::uuid(),
            'status'          => $status,
            'amount'          => $order->total,
            'external_id'     => 'pay_' . Str::random(6),
        ]);
    }

    // ─────────────────────────────────── оформление

    public function test_confirmation_goes_out_after_checkout(): void
    {
        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => 1]);

        Http::fake(['*/payments' => Http::response([
            'id'           => 'pay_1',
            'status'       => Payment::STATUS_PENDING,
            'confirmation' => ['confirmation_url' => 'https://example.test/pay'],
        ])]);

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)->post(route('checkout.store'), [
            'name'    => 'Иван',
            'phone'   => '+79001112233',
            'email'   => 'buyer@example.com',
            'consent' => '1',
        ])->assertRedirect();

        Mail::assertQueued(OrderPlaced::class, fn ($mail) => $mail->hasTo('buyer@example.com'));
    }

    // ─────────────────────────────────── оплата

    public function test_paid_letter_goes_to_the_buyer_and_the_manager(): void
    {
        $order = $this->order();

        app(PaymentService::class)->markPaid($this->payment($order));

        Mail::assertQueued(OrderPaid::class, fn ($mail) => $mail->hasTo('buyer@example.com'));
        Mail::assertQueued(OrderPaidManagerNotice::class, fn ($mail) => $mail->hasTo('manager@livsi.shop'));
    }

    /**
     * Повторный вебхук не должен слать второе письмо.
     *
     * Провайдер доставляет уведомления «хотя бы один раз», то есть дубли
     * будут обязательно. Письмо привязано к самому переходу в «оплачен»,
     * а не к факту обращения.
     */
    public function test_paid_letter_is_sent_once_however_many_times_the_webhook_arrives(): void
    {
        $order   = $this->order();
        $payment = $this->payment($order);

        $service = app(PaymentService::class);

        $service->markPaid($payment);
        $service->markPaid($payment->fresh());
        $service->markPaid($payment->fresh());

        Mail::assertQueuedCount(2); // покупателю и менеджеру, по одному
        Mail::assertQueued(OrderPaid::class, 1);
        Mail::assertQueued(OrderPaidManagerNotice::class, 1);
    }

    /** Без адреса менеджера письмо покупателю всё равно уходит. */
    public function test_missing_manager_address_does_not_stop_the_buyer_letter(): void
    {
        Setting::put('manager_email', '');
        config(['livsi.manager_email' => null]);

        Log::spy();

        $order = $this->order();

        app(PaymentService::class)->markPaid($this->payment($order));

        Mail::assertQueued(OrderPaid::class);
        Mail::assertNotQueued(OrderPaidManagerNotice::class);
        Log::shouldHaveReceived('warning')->once();
    }

    // ─────────────────────────────────── отмена

    public function test_cancellation_letter_goes_out(): void
    {
        $order = $this->order();

        app(PaymentService::class)->markCancelled($this->payment($order, Payment::STATUS_CANCELED));

        Mail::assertQueued(OrderCancelled::class, fn ($mail) => $mail->hasTo('buyer@example.com'));
    }

    /** Заказ остался в ожидании — письма об отмене быть не должно. */
    public function test_no_cancellation_letter_while_another_payment_is_alive(): void
    {
        $order = $this->order();

        $this->payment($order, Payment::STATUS_PENDING);
        $cancelled = $this->payment($order, Payment::STATUS_CANCELED);

        app(PaymentService::class)->markCancelled($cancelled);

        Mail::assertNotQueued(OrderCancelled::class);
    }

    // ─────────────────────────────────── содержание

    public function test_letter_carries_the_link_to_the_order(): void
    {
        $order = $this->order();

        $html = (new OrderPlaced($order))->render();

        $this->assertStringContainsString($order->number, $html);
        $this->assertStringContainsString($order->access_token, $html);
    }

    /**
     * Чек мы не обещаем от своего имени.
     *
     * Его выпускает оператор фискальных данных по сведениям из платежа,
     * и юридическую силу имеет именно он. Своё письмо, названное чеком,
     * вводило бы покупателя в заблуждение.
     */
    public function test_paid_letter_does_not_pretend_to_be_a_receipt(): void
    {
        $html = (new OrderPaid($this->order()))->render();

        $this->assertStringContainsString('оператора фискальных данных', $html);
    }

    /** Транзакционное письмо не рекламное: отписки в нём быть не должно. */
    public function test_transactional_letter_has_no_unsubscribe(): void
    {
        $html = (new OrderPaid($this->order()))->render();

        $this->assertStringNotContainsString('тписат', $html);
        $this->assertStringNotContainsString('тписк', $html);
    }

    // ─────────────────────────────────── проверка настроек

    /**
     * `mail:check` не пропускает заглушку в адресе отправителя.
     *
     * Письма с чужого домена почтовые службы отклоняют или кладут в спам,
     * и узнать об этом по факту первого заказа — худший способ.
     */
    public function test_mail_check_refuses_a_placeholder_sender(): void
    {
        config(['mail.from.address' => 'hello@example.com']);

        $this->artisan('mail:check')
            ->expectsOutputToContain('всё ещё заглушка')
            ->assertFailed();
    }

    public function test_mail_check_passes_with_a_real_sender(): void
    {
        config(['mail.from.address' => 'shop@livsi.shop']);

        $this->artisan('mail:check')->assertSuccessful();
    }

    // ─────────────────────────────────── устойчивость

    /**
     * Почта упала — оплата всё равно проходит.
     *
     * Деньги уже приняты; превращать сбой почтового сервера в ошибку
     * вебхука значит напрашиваться на повторные уведомления и расхождение
     * между провайдером и складом.
     */
    public function test_broken_mail_does_not_break_the_payment(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('почта недоступна'));

        Log::spy();

        $order = $this->order();

        $result = app(PaymentService::class)->markPaid($this->payment($order));

        $this->assertTrue($result->isPaid(), 'Заказ не стал оплаченным из-за письма');
        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
