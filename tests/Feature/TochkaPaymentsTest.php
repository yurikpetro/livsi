<?php

namespace Tests\Feature;

use App\Delivery\DeliverySettings;
use App\Filament\Pages\PaymentIntegrations;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemMark;
use App\Models\OrderReceipt;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockQuota;
use App\Models\User;
use App\Monitoring\ErrorReport;
use App\Payments\PaymentGateway;
use App\Payments\PaymentSettings;
use App\Payments\TochkaClient;
use App\Payments\TrustedCa;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use App\Services\RefundService;
use App\Support\Fulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Оплата через Точку Банк и чеки через CloudKassir.
 *
 * Ответы банка и кассы подставляются: поведение цепочки — ссылка с чеком,
 * уведомление, статус, второй чек с КИЗ, возврат без двойного списания —
 * проверяется без настоящих ключей.
 */
class TochkaPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const GS = "\x1D";

    private const TOCHKA = 'enter.tochka.com/uapi/';

    private const KASSA = 'api.cloudpayments.ru/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'livsi.tochka.api_url' => 'https://enter.tochka.com/uapi',
            'livsi.cloudkassir.api_url' => 'https://api.cloudpayments.ru',
            'livsi.yookassa.shop_id' => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
            'livsi.yookassa.api_url' => 'https://api.yookassa.ru/v3',
            'livsi.order_reserve_minutes' => 1440,
            'livsi.cdek.test' => true,
            'livsi.cdek.client_id' => 'cdek-id',
            'livsi.cdek.client_secret' => 'cdek-secret',
        ]);
        Setting::put('vat_rate_default', '22');
        Setting::put('closing_receipts_enabled', true);
        Setting::put('closing_receipts_since', now()->subDay()->toIso8601String());
        DeliverySettings::setOrigin('cdek', 'STV7', 'Ставрополь, Доваторцев, 84');

        PaymentSettings::setSecrets(['tochka_jwt' => 'tochka-jwt-secret-0123456789', 'cloudkassir_secret' => 'kassa-secret-0123456789']);
        PaymentSettings::setOptions([
            'tochka_customer_code' => '300123123', 'tochka_client_id' => 'client-livsi', 'tax_system' => 'usn_income',
            'cloudkassir_public_id' => 'pk_livsi', 'cloudkassir_inn' => '263400000000',
        ]);
        PaymentSettings::setProvider(PaymentSettings::TOCHKA);
    }

    // ------------------------------------------------------------ помощники

    private function order(array $overrides = [], string $gateway = 'tochka', string $paymentStatus = Payment::STATUS_SUCCEEDED, int $marks = 2): Order
    {
        $product = Product::create(['slug' => 'p-'.Str::lower(Str::random(6)), 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LV-'.Str::random(5), 'barcode' => '4870216352462',
            'option_volume' => '200 мл', 'price' => 89000, 'is_default' => true, 'is_active' => true,
            'weight_g' => 250, 'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 180, 'dimensions_verified' => true]);
        $paid = $paymentStatus === Payment::STATUS_SUCCEEDED;
        StockQuota::create(['product_variant_id' => $variant->id, 'allocated' => 10, 'reserved' => $paid ? 0 : 2, 'sold' => $paid ? 2 : 0]);
        $order = Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => 'cdek_point', 'delivery_city' => 'Москва', 'delivery_point_id' => 'MSK305',
            'delivery_point_address' => 'Москва, Тверская, 1',
            'delivery_quote' => ['direct' => true, 'provider' => 'cdek', 'tariff_id' => '136', 'tariff_name' => 'Посылка', 'carrier_cost' => 30000,
                'parcel' => ['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20]],
            'items_total' => 178000, 'delivery_price' => 30000, 'total' => 208000,
            'status' => $paid ? Order::STATUS_PAID : Order::STATUS_AWAITING_PAYMENT, 'paid_at' => $paid ? now()->subDay() : null,
            'consent_at' => now(), 'access_token' => Str::random(48),
        ], $overrides));
        $item = OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
            'title' => 'Мультипенка', 'option_label' => '200 мл', 'unit_price' => 89000, 'quantity' => 2, 'total' => 178000]);
        for ($i = 1; $i <= $marks; $i++) {
            OrderItemMark::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'gtin' => '04870216352462',
                'serial' => 'Ser'.$order->id.'x'.$i, 'code' => '0104870216352462'.'21Ser'.$order->id.'x'.$i.self::GS.'93AB'.$i.'C', 'scanned_by' => 'Мария']);
        }
        if ($gateway !== '') {
            $order->payments()->create(['gateway' => $gateway, 'idempotence_key' => (string) Str::uuid(), 'status' => $paymentStatus,
                'amount' => 208000, 'external_id' => $gateway === 'tochka' ? 'op-'.$order->id.'-0000-aaaa' : 'pay-'.$order->id]);
        }

        return $order->fresh();
    }

    private function delivered(Order $order): Order
    {
        $order->forceFill(['fulfillment_status' => Fulfillment::DELIVERED, 'fulfillment_status_at' => now()])->save();

        return $order->fresh();
    }

    /** Ответ Точки на GET платежа. @param list<array<string, mixed>> $orders */
    private static function operation(string $id, string $status, array $orders = []): array
    {
        return ['Data' => ['Operation' => [[
            'customerCode' => '300123123', 'operationId' => $id, 'status' => $status, 'amount' => 2080, 'paymentType' => 'sbp',
            'paymentLink' => 'https://merch.tochka.com/order/?uuid='.$id, 'createdAt' => now()->toIso8601String(),
            'paidAt' => $status === 'APPROVED' ? now()->toIso8601String() : null, 'Order' => $orders,
        ]]]];
    }

    /** Тело уведомления, как его шлёт Точка: JWT. */
    private static function jwt(array $payload): string
    {
        $part = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $part(['typ' => 'JWT', 'alg' => 'RS256']).'.'.$part($payload).'.c2lnbmF0dXJl';
    }

    // ------------------------------------------------------------ создание платежа

    public function test_new_payment_goes_to_tochka_link_with_receipt_items(): void
    {
        $order = $this->order([], '', Payment::STATUS_PENDING);
        Http::fake([self::TOCHKA.'acquiring/v1.0/payments_with_receipt' => Http::response(['Data' => [
            'operationId' => 'op-new-1111-2222', 'paymentLink' => 'https://merch.tochka.com/order/?uuid=op-new', 'status' => 'CREATED',
        ]])]);

        $payment = app(PaymentGateway::class)->createPayment($order);

        $this->assertSame('tochka', $payment->gateway);
        $this->assertSame('op-new-1111-2222', $payment->external_id);
        $this->assertSame('https://merch.tochka.com/order/?uuid=op-new', $payment->confirmation_url);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(208000, (int) $payment->amount);
        Http::assertSent(function (Request $request) use ($order, $payment) {
            $data = $request['Data'];

            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer tochka-jwt-secret-0123456789')
                && $data['customerCode'] === '300123123'
                && (float) $data['amount'] === 2080.0
                && $data['paymentMode'] === ['sbp', 'card']
                && $data['taxSystemCode'] === 'usn_income'
                && $data['ttl'] === 1410
                && $data['paymentLinkId'] === $order->number.'-'.$payment->id
                && $data['redirectUrl'] === $order->accessUrl()
                && $data['Client'] === ['name' => 'Анна Петрова', 'email' => 'anna@example.com', 'phone' => '+79001112233']
                && count($data['Items']) === 2
                && $data['Items'][0] === ['name' => 'Мультипенка, 200 мл', 'amount' => 890.0, 'quantity' => 2, 'vatType' => 'vat22',
                    'paymentMethod' => 'full_prepayment', 'paymentObject' => 'goods', 'measure' => 'шт.']
                && $data['Items'][1]['paymentObject'] === 'service' && (float) $data['Items'][1]['amount'] === 300.0
                && ! isset($data['merchantId']);
        });
    }

    public function test_yookassa_keeps_taking_payments_until_switched(): void
    {
        PaymentSettings::setProvider(PaymentSettings::YOOKASSA);
        $order = $this->order([], '', Payment::STATUS_PENDING);
        Http::fake(['api.yookassa.ru/v3/payments' => Http::response(['id' => 'pay_1', 'status' => 'pending',
            'confirmation' => ['type' => 'redirect', 'confirmation_url' => 'https://yoomoney.test/pay_1']])]);

        $payment = app(PaymentGateway::class)->createPayment($order);

        $this->assertSame('yookassa', $payment->gateway);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'tochka'));
    }

    public function test_rate_unknown_to_tochka_refuses_the_payment_before_any_request(): void
    {
        Setting::put('vat_rate_default', '20');
        $order = $this->order([], '', Payment::STATUS_PENDING);
        Http::fake();

        try {
            app(PaymentGateway::class)->createPayment($order);
            $this->fail('Ставка 20 % должна остановить платёж');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('20 %', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_bank_refusal_leaves_a_cancelled_attempt_and_explains(): void
    {
        $order = $this->order([], '', Payment::STATUS_PENDING);
        Http::fake([self::TOCHKA.'*' => Http::response(['code' => '400', 'message' => 'Bad request',
            'Errors' => [['errorCode' => 'Validation', 'message' => 'customerCode not found']]], 400)]);

        try {
            app(PaymentGateway::class)->createPayment($order);
            $this->fail('Отказ банка должен дойти до оформления');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('customerCode not found', $exception->getMessage());
        }
        $this->assertSame(Payment::STATUS_CANCELED, $order->payments()->first()->status);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------ уведомление и статус

    public function test_notification_marks_the_order_paid_after_asking_the_bank(): void
    {
        $order = $this->order([], 'tochka', Payment::STATUS_PENDING);
        $payment = $order->payments()->first();
        Http::fake([self::TOCHKA.'acquiring/v1.0/payments/'.$payment->external_id => Http::response(self::operation($payment->external_id, 'APPROVED'))]);

        $this->call('POST', '/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            self::jwt(['webhookType' => 'acquiringInternetPayment', 'operationId' => $payment->external_id, 'status' => 'APPROVED']))
            ->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertSame('sbp', $payment->payment_method);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $quota = StockQuota::query()->first();
        $this->assertSame([0, 2], [(int) $quota->reserved, (int) $quota->sold]);
    }

    public function test_notification_body_is_not_trusted(): void
    {
        $order = $this->order([], 'tochka', Payment::STATUS_PENDING);
        $payment = $order->payments()->first();
        Http::fake([self::TOCHKA.'*' => Http::response(self::operation($payment->external_id, 'CREATED'))]);

        $this->call('POST', '/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            self::jwt(['operationId' => $payment->external_id, 'status' => 'APPROVED']))->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_test_notification_garbage_and_foreign_operations_are_answered_ok(): void
    {
        Http::fake();

        foreach (['', 'not a jwt', self::jwt(['webhookType' => 'acquiringInternetPayment']), self::jwt(['operationId' => 'beeac8a4-6047-3f38-8922-a664e6b5c43b'])] as $body) {
            $this->call('POST', '/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();
        }

        Http::assertNothingSent();
    }

    public function test_expired_link_cancels_the_order_and_frees_the_goods(): void
    {
        $order = $this->order([], 'tochka', Payment::STATUS_PENDING);
        $payment = $order->payments()->first();
        Http::fake([self::TOCHKA.'*' => Http::response(self::operation($payment->external_id, 'EXPIRED'))]);

        app(PaymentService::class)->sync(app(PaymentGateway::class)->refreshStatus($payment));

        $this->assertSame(Payment::STATUS_CANCELED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, (int) StockQuota::query()->first()->reserved);
    }

    public function test_link_long_past_its_life_counts_as_unpaid(): void
    {
        $order = $this->order([], 'tochka', Payment::STATUS_PENDING);
        $payment = $order->payments()->first();
        $payment->forceFill(['created_at' => now()->subMinutes(1410 + 31)])->save();
        Http::fake([self::TOCHKA.'*' => Http::response(self::operation($payment->external_id, 'CREATED'))]);

        $this->assertSame(Payment::STATUS_CANCELED, app(PaymentGateway::class)->refreshStatus($payment)->status);
    }

    public function test_paid_payment_is_not_turned_back_by_a_refund_status(): void
    {
        $order = $this->order();
        $payment = $order->payments()->first();
        Http::fake([self::TOCHKA.'*' => Http::response(self::operation($payment->external_id, 'REFUNDED'))]);

        $this->assertSame(Payment::STATUS_SUCCEEDED, app(PaymentGateway::class)->refreshStatus($payment)->status);
    }

    public function test_old_yookassa_payment_is_checked_with_yookassa_after_the_switch(): void
    {
        $order = $this->order([], 'yookassa', Payment::STATUS_PENDING);
        $payment = $order->payments()->first();
        Http::fake(['api.yookassa.ru/v3/payments/'.$payment->external_id => Http::response(['id' => $payment->external_id, 'status' => 'succeeded'])]);

        $this->assertSame(Payment::STATUS_SUCCEEDED, app(PaymentGateway::class)->refreshStatus($payment)->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'tochka'));
    }

    // ------------------------------------------------------------ второй чек через CloudKassir

    public function test_closing_receipt_for_tochka_payment_goes_to_cloudkassir_with_codes(): void
    {
        $order = $this->delivered($this->order());
        Http::fake([self::KASSA.'kkt/receipt' => Http::response(['Success' => true, 'Message' => 'Queued',
            'Model' => ['Id' => 'ck-receipt-1', 'ErrorCode' => 0, 'ReceiptLocalUrl' => 'https://receipts.ru/ck-receipt-1']])]);

        $this->assertTrue(app(ReceiptService::class)->due()->contains('id', $order->id));
        $record = app(ReceiptService::class)->sendClosing($order);

        $this->assertSame(OrderReceipt::PENDING, $record->status);
        $this->assertSame('ck-receipt-1', $record->external_id);
        Http::assertSent(function (Request $request) use ($order, $record) {
            $receipt = $request['CustomerReceipt'];
            $items = $receipt['Items'];

            return $request->hasHeader('X-Request-ID', $record->idempotence_key)
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('pk_livsi:kassa-secret-0123456789'))
                && $request['Inn'] === '263400000000' && $request['Type'] === 'Income' && $request['InvoiceId'] === $order->number
                && $receipt['TaxationSystem'] === 1 && $receipt['Email'] === 'anna@example.com' && $receipt['Phone'] === '+79001112233'
                && $receipt['IsInternetPayment'] === true
                && (float) $receipt['Amounts']['AdvancePayment'] === 2080.0 && ! isset($receipt['Amounts']['Electronic'])
                && count($items) === 3
                && $items[0]['Object'] === 33 && $items[0]['Method'] === 4 && $items[0]['Quantity'] === 1 && $items[0]['Vat'] === 22
                && $items[0]['UnitCode'] === 0 && $items[0]['ExpectedMarkItemStatus'] === 1
                && str_contains($items[0]['RawMarkCode'], self::GS.'93AB1C')
                && $items[1]['RawMarkCode'] !== $items[0]['RawMarkCode']
                && $items[2]['Object'] === 4 && ! isset($items[2]['RawMarkCode']) && (float) $items[2]['Amount'] === 300.0;
        });

        // Касса пробила — запись закрывается со следующего опроса.
        Http::fake([self::KASSA.'kkt/receipt/status/get' => Http::response(['Success' => true, 'Model' => 'Processed'])]);
        $this->assertSame(OrderReceipt::SUCCEEDED, app(ReceiptService::class)->refresh($record->fresh())->status);
    }

    public function test_receipt_rejected_by_cloudkassir_is_visible_and_not_repeated(): void
    {
        $order = $this->delivered($this->order());
        Http::fake([self::KASSA.'kkt/receipt' => Http::response(['Success' => false, 'Message' => 'Касса не найдена', 'Model' => ['ErrorCode' => -1]])]);

        $record = app(ReceiptService::class)->sendClosing($order);

        $this->assertSame(OrderReceipt::CANCELED, $record->status);
        $this->assertStringContainsString('Касса не найдена', $record->error);
        $this->assertFalse(app(ReceiptService::class)->due()->contains('id', $order->id));
    }

    public function test_tochka_orders_wait_for_cloudkassir_keys(): void
    {
        PaymentSettings::setOptions(['cloudkassir_public_id' => '']);
        $tochka = $this->delivered($this->order());
        $yookassa = $this->delivered($this->order([], 'yookassa'));

        $due = app(ReceiptService::class)->due();

        $this->assertFalse($due->contains('id', $tochka->id));
        $this->assertTrue($due->contains('id', $yookassa->id));
    }

    // ------------------------------------------------------------ возврат через Точку

    public function test_refund_goes_through_tochka_and_no_site_receipt_by_default(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order();
        $id = $order->payments()->first()->external_id;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => Http::response(['Data' => ['isRefund' => true, 'operationId' => $id,
                'amount' => 2080, 'date' => now()->toDateString(), 'orderId' => 'refund-order-1']]),
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => Http::response(self::operation($id, 'APPROVED')),
        ]);

        $refund = app(RefundService::class)->refund($order, 'Покупатель передумал', 'admin');

        $this->assertSame(OrderReceipt::SUCCEEDED, $refund->status);
        $this->assertSame('refund-order-1', $refund->external_id);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(0, (int) StockQuota::query()->first()->sold);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund') && (float) $request['Data']['amount'] === 2080.0);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cloudpayments'));
        $this->assertNull($order->fresh()->receipt(OrderReceipt::KIND_REFUND_RECEIPT));
    }

    public function test_refund_after_closing_receipt_returns_codes_when_site_prints_refund_receipts(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        PaymentSettings::setOptions(['cloudkassir_refund_receipts' => true]);
        $order = $this->delivered($this->order());
        OrderReceipt::create(['order_id' => $order->id, 'kind' => OrderReceipt::KIND_CLOSING, 'status' => OrderReceipt::SUCCEEDED,
            'idempotence_key' => (string) Str::uuid(), 'amount' => 208000, 'payload' => ['provider' => 'cloudkassir'], 'sent_at' => now()]);
        $id = $order->payments()->first()->external_id;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => Http::response(['Data' => ['isRefund' => true, 'operationId' => $id,
                'amount' => 2080, 'date' => now()->toDateString(), 'orderId' => 'refund-order-2']]),
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => Http::response(self::operation($id, 'APPROVED')),
            self::KASSA.'kkt/receipt' => Http::response(['Success' => true, 'Message' => 'Queued', 'Model' => ['Id' => 'ck-return-1', 'ErrorCode' => 0]]),
        ]);

        app(RefundService::class)->refund($order, 'Возврат товара', 'admin');

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), 'kkt/receipt')) {
                return false;
            }
            $items = $request['CustomerReceipt']['Items'];

            return $request['Type'] === 'IncomeReturn'
                && (float) $request['CustomerReceipt']['Amounts']['Electronic'] === 2080.0
                && $items[0]['ExpectedMarkItemStatus'] === 3 && $items[0]['Method'] === 4 && $items[0]['Object'] === 33
                && str_contains($items[0]['RawMarkCode'], self::GS);
        });
        $receipt = $order->fresh()->receipt(OrderReceipt::KIND_REFUND_RECEIPT);
        $this->assertSame(OrderReceipt::PENDING, $receipt->status);
        $this->assertSame('ck-return-1', $receipt->external_id);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_refund_before_closing_receipt_returns_prepayment_when_site_prints_refund_receipts(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        PaymentSettings::setOptions(['cloudkassir_refund_receipts' => true]);
        $order = $this->order();
        $id = $order->payments()->first()->external_id;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => Http::response(['Data' => ['isRefund' => true, 'operationId' => $id,
                'amount' => 1780, 'date' => now()->toDateString(), 'orderId' => 'refund-order-3']]),
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => Http::response(self::operation($id, 'APPROVED')),
            self::KASSA.'kkt/receipt' => Http::response(['Success' => true, 'Message' => 'Queued', 'Model' => ['Id' => 'ck-return-2', 'ErrorCode' => 0]]),
        ]);

        app(RefundService::class)->refund($order, 'Не забрал', 'admin', keepDelivery: true);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund') && (float) $request['Data']['amount'] === 1780.0);
        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), 'kkt/receipt')) {
                return false;
            }
            $items = $request['CustomerReceipt']['Items'];

            return count($items) === 1 && $items[0]['Method'] === 1 && $items[0]['Object'] === 1 && $items[0]['Quantity'] === 2
                && ! isset($items[0]['RawMarkCode']) && (float) $request['CustomerReceipt']['Amounts']['Electronic'] === 1780.0;
        });
    }

    public function test_refund_lost_on_network_is_not_made_twice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order();
        $id = $order->payments()->first()->external_id;
        $refunded = false;
        $calls = 0;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => function () use (&$refunded, &$calls) {
                $refunded = true;
                $calls++;

                throw new ConnectionException('timeout');
            },
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => function () use (&$refunded, $id) {
                return Http::response($refunded
                    ? self::operation($id, 'REFUNDED', [['orderId' => 'refund-order-9', 'type' => 'refund', 'amount' => 2080, 'time' => now()->toIso8601String()]])
                    : self::operation($id, 'APPROVED'));
            },
        ]);

        try {
            app(RefundService::class)->refund($order, 'Тест', 'admin');
            $this->fail('Обрыв связи должен дойти до администратора');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Нет связи с Точкой', $exception->validator->errors()->first());
        }
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertTrue(app(RefundService::class)->canRefund($order->fresh()));

        $refund = app(RefundService::class)->refund($order->fresh(), 'Тест', 'admin');

        $this->assertSame(OrderReceipt::SUCCEEDED, $refund->status);
        $this->assertSame('refund-order-9', $refund->external_id);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(1, $calls, 'Второй возврат не делается: банк уже вернул деньги');
    }

    public function test_refund_refused_by_the_bank_keeps_the_order_and_its_shipment_queue(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order(['shipment_state' => 'queued']);
        $id = $order->payments()->first()->external_id;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => Http::response(['message' => 'Refund not allowed'], 400),
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => Http::response(self::operation($id, 'APPROVED')),
        ]);

        try {
            app(RefundService::class)->refund($order, 'Тест', 'admin');
            $this->fail('Отказ банка должен дойти до администратора');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Refund not allowed', $exception->validator->errors()->first());
        }
        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame('queued', $order->shipment_state);
        $this->assertSame(OrderReceipt::CANCELED, $order->receipt(OrderReceipt::KIND_REFUND)->status);
    }

    // ------------------------------------------------------------ админка

    public function test_check_finds_the_company_code_by_itself(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        PaymentSettings::setOptions(['tochka_customer_code' => '']);
        Http::fake([
            self::TOCHKA.'open-banking/v1.0/customers' => Http::response(['Data' => ['Customer' => [
                ['customerCode' => '300999888', 'customerType' => 'Personal', 'isResident' => true, 'fullName' => 'Физлицо'],
                ['customerCode' => '300555777', 'customerType' => 'Business', 'isResident' => true, 'fullName' => 'ИП Волкодав'],
            ]]]),
            self::TOCHKA.'acquiring/v1.0/retailers*' => Http::response(['Data' => ['Retailer' => [['status' => 'REG', 'isActive' => true, 'mcc' => '5977',
                'rate' => 1.5, 'name' => 'LIVSI', 'merchantId' => '200000000001234', 'paymentModes' => ['sbp', 'card'], 'cashbox' => 'CloudKassir']]]]),
        ]);

        Livewire::test(PaymentIntegrations::class)
            ->call('checkTochka')
            ->assertNotified('Точка: эквайринг подключён')
            ->assertSet('data.tochka_customer_code', '300555777');

        $this->assertSame('300555777', PaymentSettings::customerCode());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'retailers') && $request['customerCode'] === '300555777');
    }

    public function test_untrusted_bank_certificate_is_explained(): void
    {
        Http::fake([self::TOCHKA.'*' => fn () => throw new ConnectionException(
            'cURL error 60: SSL certificate problem: self-signed certificate in certificate chain (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)')]);

        try {
            app(TochkaClient::class)->customers();
            $this->fail('Ошибка сертификата должна дойти до владельца');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('сертификат Минцифры', $exception->getMessage());
        }
    }

    public function test_tochka_trusts_the_ministry_root_only_when_it_is_installed(): void
    {
        $root = storage_path('app/'.TrustedCa::ROOT);
        File::delete($root);
        $this->assertTrue(TrustedCa::verify());
        $this->assertTrue(TrustedCa::combined());

        File::ensureDirectoryExists(dirname($root));
        File::put($root, "-----BEGIN CERTIFICATE-----\nMINISTRY-ROOT\n-----END CERTIFICATE-----\n");
        try {
            $bundle = TrustedCa::verify();
            $this->assertIsString($bundle);
            $content = File::get($bundle);
            $this->assertStringEndsWith("MINISTRY-ROOT\n-----END CERTIFICATE-----\n", $content);
            $this->assertGreaterThan(10000, strlen($content), 'в наборе и системные корни — обычные сертификаты не ломаются');
            $this->assertSame($bundle, TrustedCa::combined());
        } finally {
            File::delete($root);
            File::delete(storage_path('app/certs/ca-bundle-tochka.pem'));
        }
    }

    public function test_switching_to_tochka_needs_keys(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        PaymentSettings::setProvider(PaymentSettings::YOOKASSA);
        PaymentSettings::setOptions(['tochka_customer_code' => '']);

        Livewire::test(PaymentIntegrations::class)
            ->set('data.provider', PaymentSettings::TOCHKA)
            ->call('save');
        $this->assertSame(PaymentSettings::YOOKASSA, PaymentSettings::provider());

        Livewire::test(PaymentIntegrations::class)
            ->set('data.provider', PaymentSettings::TOCHKA)
            ->set('data.tochka_customer_code', '300123123')
            ->set('data.tochka_jwt', "  new-jwt-from-bank\n  ")
            ->call('save')
            ->assertSet('data.tochka_jwt', null);
        $this->assertSame(PaymentSettings::TOCHKA, PaymentSettings::provider());
        $this->assertSame('new-jwt-from-bank', PaymentSettings::secret('tochka_jwt'));
        $this->assertStringNotContainsString('new-jwt-from-bank', json_encode(Setting::get('payment_credentials')));
        $this->assertSame('kassa-secret-0123456789', PaymentSettings::secret('cloudkassir_secret'));
    }

    public function test_admin_page_checks_the_bank_and_creates_a_test_link(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'owner@livsi.test']));
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/retailers*' => Http::response(['Data' => ['Retailer' => [['status' => 'REG', 'isActive' => true, 'mcc' => '5977',
                'rate' => 1.5, 'name' => 'LIVSI', 'merchantId' => '200000000001234', 'paymentModes' => ['sbp', 'card'], 'cashbox' => 'CloudKassir']]]]),
            self::TOCHKA.'acquiring/v1.0/payments_with_receipt' => Http::response(['Data' => ['operationId' => 'op-test-1234-5678',
                'paymentLink' => 'https://merch.tochka.com/order/?uuid=op-test', 'status' => 'CREATED']]),
        ]);

        Livewire::test(PaymentIntegrations::class)
            ->assertSee('Интеграции оплаты')
            ->call('checkTochka')
            ->assertNotified('Точка: эквайринг подключён')
            ->call('testPayment')
            ->assertNotified('Ссылка на 10 ₽ готова');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'payments_with_receipt')
            && (float) $request['Data']['amount'] === 10.0 && $request['Data']['Client']['email'] === 'owner@livsi.test'
            && $request['Data']['Items'][0]['vatType'] === 'vat22');
        $this->assertSame('op-test-1234-5678', PaymentSettings::option('tochka_test')['operation']);
    }

    public function test_tochka_secrets_are_hidden_from_error_reports(): void
    {
        $text = ErrorReport::redact('Authorization: Bearer tochka-jwt-secret-0123456789; kassa kassa-secret-0123456789');

        $this->assertStringNotContainsString('tochka-jwt-secret', $text);
        $this->assertStringNotContainsString('kassa-secret', $text);
    }
}
