<?php

namespace Tests\Feature;

use App\Delivery\DeliverySettings;
use App\Filament\Resources\Orders\Pages\EditOrder;
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
use App\Payments\ReceiptBuilder;
use App\Services\ReceiptService;
use App\Services\RefundService;
use App\Support\Fulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptsAndRefundsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'livsipro-test-token-0123456789-abcdefghijklmnop';

    private const GS = "\x1D";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'livsi.yookassa.shop_id' => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
            'livsi.yookassa.api_url' => 'https://api.yookassa.ru/v3',
            'livsi.livsipro.token' => self::TOKEN,
            'livsi.livsipro.ips' => [],
            'livsi.cdek.test' => true,
            'livsi.cdek.client_id' => 'cdek-id',
            'livsi.cdek.client_secret' => 'cdek-secret',
        ]);
        Setting::put('vat_rate_default', '20');
        Setting::put('closing_receipts_since', now()->subDay()->toIso8601String());
        DeliverySettings::setOrigin('cdek', 'STV7', 'Ставрополь, Доваторцев, 84');
    }

    // ------------------------------------------------------------ помощники

    private function order(array $overrides = [], int $quantity = 2, int $marks = 2): Order
    {
        $product = Product::create(['slug' => 'p-'.Str::lower(Str::random(6)), 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LV-'.Str::random(5), 'barcode' => '4870216352462',
            'option_volume' => '200 мл', 'price' => 89000, 'is_default' => true, 'is_active' => true,
            'weight_g' => 250, 'length_mm' => 50, 'width_mm' => 50, 'height_mm' => 180, 'dimensions_verified' => true]);
        $order = Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)),
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => 'cdek_point', 'delivery_city' => 'Москва', 'delivery_point_id' => 'MSK305',
            'delivery_point_address' => 'Москва, Тверская, 1',
            'delivery_quote' => ['direct' => true, 'provider' => 'cdek', 'tariff_id' => '136', 'tariff_name' => 'Посылка', 'carrier_cost' => 30000,
                'parcel' => ['weight' => 700, 'length' => 12, 'width' => 12, 'height' => 20]],
            'items_total' => 89000 * $quantity, 'total' => 89000 * $quantity, 'status' => Order::STATUS_PAID, 'paid_at' => now()->subDay(),
            'consent_at' => now(), 'access_token' => Str::random(48),
        ], $overrides));
        $item = OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
            'title' => 'Мультипенка', 'option_label' => '200 мл', 'unit_price' => 89000, 'quantity' => $quantity, 'total' => 89000 * $quantity]);
        for ($i = 1; $i <= $marks; $i++) {
            OrderItemMark::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'gtin' => '04870216352462',
                'serial' => 'Ser'.$order->id.'x'.$i, 'code' => '0104870216352462'.'21Ser'.$order->id.'x'.$i.self::GS.'93AB'.$i.'C', 'scanned_by' => 'Мария']);
        }
        $order->payments()->create(['gateway' => 'yookassa', 'idempotence_key' => (string) Str::uuid(), 'status' => Payment::STATUS_SUCCEEDED,
            'amount' => $order->total, 'external_id' => 'pay-'.$order->id]);

        return $order->fresh();
    }

    private function delivered(Order $order): Order
    {
        $order->forceFill(['fulfillment_status' => Fulfillment::DELIVERED, 'fulfillment_status_at' => now()])->save();

        return $order->fresh();
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->json($method, '/api/livsipro'.$uri, $data);
    }

    // ------------------------------------------------------------ второй чек

    public function test_closing_receipt_has_full_payment_and_own_code_per_piece(): void
    {
        $order = $this->order(['delivery_price' => 30000, 'total' => 208000]);
        $items = (new ReceiptBuilder($order))->closingItems();

        $this->assertCount(3, $items);
        $this->assertSame(['marked', 'marked', 'service'], array_column($items, 'payment_subject'));
        $this->assertSame(['full_payment'], array_values(array_unique(array_column($items, 'payment_mode'))));
        $this->assertSame('1', $items[0]['quantity']);
        $this->assertSame('piece', $items[0]['measure']);
        $this->assertSame(0, $items[0]['mark_mode']);
        $this->assertStringContainsString(self::GS.'93AB1C', $items[0]['mark_code_info']['gs_1m']);   // как отсканирован, не base64
        $this->assertSame(208000, ReceiptBuilder::sum($items));
    }

    public function test_pieces_without_code_go_as_plain_goods_and_monitoring_is_warned(): void
    {
        Log::spy();
        $order = $this->delivered($this->order([], 3, 1));
        Http::fake(['*/v3/receipts' => Http::response(['id' => 'rcpt-1', 'status' => 'pending'])]);

        app(ReceiptService::class)->sendClosing($order);

        Http::assertSent(function (Request $request) {
            $items = $request['items'];

            return count($items) === 2 && $items[0]['payment_subject'] === 'marked' && $items[1]['payment_subject'] === 'commodity'
                && $items[1]['quantity'] === '2';
        });
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'КИЗ отсканированы не по всем штукам'))->once();
    }

    public function test_closing_receipt_goes_once_for_delivered_order_with_prepayment_settlement(): void
    {
        $order = $this->delivered($this->order());
        Http::fake([
            '*/v3/receipts' => Http::response(['id' => 'rcpt-1', 'status' => 'pending']),
            '*/v3/receipts/rcpt-1' => Http::response(['id' => 'rcpt-1', 'status' => 'pending']),
        ]);

        $this->artisan('receipts:sync')->assertSuccessful();
        $this->artisan('receipts:sync')->assertSuccessful();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.$pair[0]->url())->all();
        $this->assertSame(['POST https://api.yookassa.ru/v3/receipts', 'GET https://api.yookassa.ru/v3/receipts/rcpt-1'], $sent);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.yookassa.ru/v3/receipts'
            && $request['type'] === 'payment' && $request['payment_id'] === 'pay-'.$order->id && $request['send'] === true
            && $request['settlements'] === [['type' => 'prepayment', 'amount' => ['value' => '1780.00', 'currency' => 'RUB']]]
            && $request['customer']['email'] === 'anna@example.com'
            && str_contains($request->body(), '\u001d93AB1C')   // GS — как \u001d в JSON
            && filled($request->header('Idempotence-Key')[0] ?? null));
        $receipt = $order->receipt(OrderReceipt::KIND_CLOSING);
        $this->assertSame([OrderReceipt::PENDING, 'rcpt-1'], [$receipt->status, $receipt->external_id]);
    }

    public function test_pending_receipt_is_checked_until_yookassa_registers_it(): void
    {
        $order = $this->delivered($this->order());
        $answer = 'pending';
        Http::fake([
            '*/v3/receipts' => Http::response(['id' => 'rcpt-1', 'status' => 'pending']),
            '*/v3/receipts/rcpt-1' => function () use (&$answer) {
                return Http::response(['id' => 'rcpt-1', 'status' => $answer]);
            },
        ]);
        $this->artisan('receipts:sync');
        $answer = 'succeeded';
        $this->artisan('receipts:sync');

        $receipt = $order->receipt(OrderReceipt::KIND_CLOSING);
        $this->assertSame(OrderReceipt::SUCCEEDED, $receipt->status);
        $this->assertNotNull($receipt->done_at);
    }

    public function test_orders_received_before_the_feature_and_not_received_are_skipped(): void
    {
        Http::fake();
        $old = $this->order();
        $old->forceFill(['fulfillment_status' => Fulfillment::DELIVERED, 'fulfillment_status_at' => now()->subDays(3)])->save();
        $this->order(['fulfillment_status' => Fulfillment::SHIPPED]);

        $this->artisan('receipts:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_rejected_receipt_is_not_repeated_by_itself_but_can_be_retried(): void
    {
        $order = $this->delivered($this->order());
        $fail = true;
        Http::fake(['*/v3/receipts' => function () use (&$fail) {
            return $fail
                ? Http::response(['type' => 'error', 'code' => 'invalid_request', 'description' => 'Неверный формат кода', 'parameter' => 'items'], 400)
                : Http::response(['id' => 'rcpt-2', 'status' => 'pending']);
        }]);

        $this->artisan('receipts:sync');
        $this->artisan('receipts:sync');
        Http::assertSentCount(1);
        $this->assertStringContainsString('Неверный формат кода', $order->receipt(OrderReceipt::KIND_CLOSING)->error);

        $fail = false;
        $this->artisan('receipts:sync', ['--retry' => $order->number])->assertSuccessful();
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotence-Key')[0])->unique();
        $this->assertCount(2, $keys);   // повтор — с новым ключом
        $this->assertSame('rcpt-2', $order->receipt(OrderReceipt::KIND_CLOSING)->external_id);
    }

    public function test_amount_mismatch_is_not_sent(): void
    {
        $order = $this->delivered($this->order());
        $order->payments()->update(['amount' => 100]);
        Http::fake();

        $record = app(ReceiptService::class)->sendClosing($order);

        Http::assertNothingSent();
        $this->assertSame(OrderReceipt::CANCELED, $record->status);
        $this->assertStringContainsString('не совпадает с оплатой', $record->error);
    }

    // ------------------------------------------------------------ возврат

    public function test_refund_before_shipping_cancels_carrier_order_returns_money_stock_and_codes(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED, 'shipment_provider' => 'cdek',
            'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created', 'shipment_tracking_number' => '1500']);
        $quota = StockQuota::create(['product_variant_id' => $order->items->first()->product_variant_id, 'allocated' => 10, 'reserved' => 0, 'sold' => 2]);
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/orders/uuid-1' => Http::response(['entity' => ['uuid' => 'uuid-1'], 'requests' => [['state' => 'ACCEPTED']]]),
            '*/v3/refunds' => Http::response(['id' => 'ref-1', 'status' => 'succeeded']),
        ]);

        $refund = app(RefundService::class)->refund($order, 'Покупатель передумал', 'admin@livsi.shop');

        $this->assertSame(OrderReceipt::SUCCEEDED, $refund->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/v2/orders/uuid-1'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/refunds') && $request['payment_id'] === 'pay-'.$order->id
            && $request['amount'] === ['value' => '1780.00', 'currency' => 'RUB'] && ! isset($request['receipt']));
        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertNull($order->shipment_external_id);
        $this->assertSame(0, $order->marks()->count());
        $this->assertCount(2, $refund->payload['released_marks']);
        $this->assertSame(0, $quota->fresh()->sold);
    }

    public function test_refund_after_closing_receipt_goes_with_codes(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->delivered($this->order());
        $order->receipts()->create(['kind' => OrderReceipt::KIND_CLOSING, 'status' => OrderReceipt::SUCCEEDED, 'idempotence_key' => 'k', 'amount' => 178000, 'sent_at' => now()]);
        Http::fake(['*/v3/refunds' => Http::response(['id' => 'ref-1', 'status' => 'pending'])]);

        $refund = app(RefundService::class)->refund($order, 'Возврат после получения', 'admin');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/refunds')
            && count($request['receipt']['items']) === 2 && $request['receipt']['items'][0]['payment_subject'] === 'marked'
            && $request['receipt']['items'][0]['payment_mode'] === 'full_payment');
        $this->assertSame(OrderReceipt::PENDING, $refund->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);   // пока ЮKassa не подтвердила

        Http::fake(['*/v3/refunds/ref-1' => Http::response(['id' => 'ref-1', 'status' => 'succeeded'])]);
        $this->artisan('receipts:sync');
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_rejected_refund_keeps_the_order_paid(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order();
        Http::fake(['*/v3/refunds' => Http::response(['type' => 'error', 'description' => 'Недостаточно средств'], 400)]);

        try {
            app(RefundService::class)->refund($order, 'Тест', 'admin');
            $this->fail('Отказ ЮKassa должен дойти до администратора');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Недостаточно средств', $exception->validator->errors()->first());
        }
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(2, $order->marks()->count());
    }

    public function test_admin_order_page_shows_refund_action_and_receipts_line(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('refundOrder')
            ->assertSee('Второй чек уйдёт сам');
    }

    public function test_admin_refunds_from_order_page_with_reason(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'boss@livsi.shop']));
        $order = $this->order(['fulfillment_status' => Fulfillment::AWAITING]);
        Http::fake(['*/v3/refunds' => Http::response(['id' => 'ref-1', 'status' => 'succeeded'])]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('refundOrder', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        Http::assertNothingSent();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('refundOrder', ['reason' => 'Нет в наличии'])
            ->assertHasNoActionErrors()
            ->assertNotified('Деньги возвращены, заказ отменён');

        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $refund = $order->receipt(OrderReceipt::KIND_REFUND);
        $this->assertSame('boss@livsi.shop', $refund->created_by);
        $this->assertStringContainsString('Нет в наличии', $order->fulfillmentEvents()->latest('id')->first()->comment);
    }

    public function test_admin_cancels_carrier_shipment_from_order_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED, 'shipment_provider' => 'cdek',
            'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created']);
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/orders/uuid-1' => Http::response(['entity' => ['uuid' => 'uuid-1']]),
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('cancelShipment')
            ->callAction('cancelShipment')
            ->assertNotified('Отправление отменено');

        $this->assertNull($order->fresh()->shipment_external_id);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // ------------------------------------------------------------ livsipro

    public function test_livsipro_returns_assembled_order_back_to_assembly(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED]);

        $this->api('POST', '/orders/'.$order->number.'/reassemble', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.fulfillment.code', Fulfillment::AWAITING)
            ->assertJsonPath('order.actions.reassemble', false);

        $order->forceFill(['fulfillment_status' => Fulfillment::ASSEMBLED, 'shipment_external_id' => 'uuid-1', 'shipment_provider' => 'cdek'])->save();
        $this->api('POST', '/orders/'.$order->number.'/reassemble', ['by' => 'Мария'])->assertStatus(422);
    }

    public function test_livsipro_cancels_shipment_and_next_request_goes_with_new_number(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::ASSEMBLED, 'shipment_provider' => 'cdek',
            'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created']);
        Http::fake([
            '*/v2/oauth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/orders/uuid-1' => Http::response(['entity' => ['uuid' => 'uuid-1']]),
        ]);

        $this->api('GET', '/orders/'.$order->number)->assertJsonPath('order.actions.cancel_shipment', true);
        $this->api('POST', '/orders/'.$order->number.'/cancel-shipment', ['by' => 'Мария'])->assertOk()
            ->assertJsonPath('order.shipment.state', 'cancelled')
            ->assertJsonPath('order.actions.reassemble', true);

        $order->refresh();
        $this->assertNull($order->shipment_external_id);
        $this->assertSame(2, (int) $order->shipment_attempt);
        $this->assertSame($order->number.'-2', $order->shipmentRef());
        $this->assertStringContainsString('отменено у службы', $order->fulfillmentEvents()->latest('id')->first()->comment);
    }

    public function test_livsipro_cannot_cancel_shipment_after_handover(): void
    {
        $order = $this->order(['fulfillment_status' => Fulfillment::SHIPPED, 'shipment_provider' => 'cdek',
            'shipment_external_id' => 'uuid-1', 'shipment_state' => 'created']);
        Http::fake();

        $this->api('POST', '/orders/'.$order->number.'/cancel-shipment', ['by' => 'Мария'])->assertStatus(422);
        Http::assertNothingSent();
    }
}
