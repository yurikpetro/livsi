<?php

namespace Tests\Feature;

use App\Delivery\DeliverySettings;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Mail\OrderReturnMail;
use App\Mail\OrderReturnManagerNotice;
use App\Models\DeliveryPoint;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\OrderItemMark;
use App\Models\OrderReceipt;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\StockQuota;
use App\Models\User;
use App\Payments\PaymentSettings;
use App\Services\ReturnService;
use App\Support\Fulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Возврат после получения: заявка покупателя → решение магазина →
 * накладная СДЭК или курьер → товар получен → деньги через Точку.
 */
class OrderReturnsTest extends TestCase
{
    use RefreshDatabase;

    private const TOCHKA = 'enter.tochka.com/uapi/';

    private const CDEK = 'api.edu.cdek.ru/v2/';

    private ProductVariant $foam;

    private ProductVariant $cream;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Storage::fake('local');
        config([
            'livsi.tochka.api_url' => 'https://enter.tochka.com/uapi',
            'livsi.cloudkassir.api_url' => 'https://api.cloudpayments.ru',
            'livsi.cdek.test' => true,
            'livsi.cdek.poll_microseconds' => 0,
        ]);
        Setting::put('manager_email', 'boss@livsi.test');
        Setting::put('delivery_sender_phone', '+7 928 000-00-00');
        SellerProfile::create(['code' => 'ip', 'legal_name' => 'ИП Волкодав', 'inn' => '054701031550', 'is_default' => true]);
        DeliverySettings::setOrigin('cdek', 'STV7', 'Ставрополь, Доваторцев, 84');
        PaymentSettings::setSecrets(['tochka_jwt' => 'tochka-jwt-secret-0123456789', 'cloudkassir_secret' => 'kassa-secret-0123456789']);
        PaymentSettings::setOptions(['tochka_customer_code' => '300123123', 'tochka_client_id' => 'client-livsi', 'tax_system' => 'usn_income',
            'cloudkassir_public_id' => 'pk_livsi', 'cloudkassir_inn' => '054701031550']);
        PaymentSettings::setProvider(PaymentSettings::TOCHKA);
    }

    /** Пенка × 3 за 1000 ₽ (цены по штукам 333,33 / 333,33 / 333,34) и крем × 1 за 450 ₽, доставка 300 ₽. */
    private function order(array $overrides = [], string $gateway = 'tochka', ?User $user = null): Order
    {
        $product = Product::create(['slug' => 'p-'.Str::lower(Str::random(6)), 'title' => 'Мультипенка', 'is_active' => true, 'sort' => 1]);
        $this->foam = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LV-F'.Str::random(4), 'option_volume' => '200 мл',
            'price' => 33334, 'is_default' => true, 'is_active' => true, 'weight_g' => 250]);
        $this->cream = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LV-C'.Str::random(4), 'option_volume' => '50 мл',
            'price' => 45000, 'is_default' => false, 'is_active' => true, 'weight_g' => 120]);
        StockQuota::create(['product_variant_id' => $this->foam->id, 'allocated' => 10, 'reserved' => 0, 'sold' => 3]);
        StockQuota::create(['product_variant_id' => $this->cream->id, 'allocated' => 10, 'reserved' => 0, 'sold' => 1]);
        $order = Order::create(array_merge([
            'number' => 'LV-'.Str::upper(Str::random(6)), 'user_id' => $user?->id,
            'customer_name' => 'Анна Петрова', 'customer_phone' => '+79001112233', 'customer_email' => 'anna@example.com',
            'delivery_method' => 'cdek_point', 'delivery_city' => 'Москва', 'delivery_point_id' => 'MSK305',
            'delivery_point_address' => 'Москва, Тверская, 1',
            'delivery_quote' => ['direct' => true, 'provider' => 'cdek', 'tariff_id' => '136', 'tariff_name' => 'Посылка', 'carrier_cost' => 30000,
                'parcel' => ['weight' => 1200, 'length' => 20, 'width' => 15, 'height' => 12]],
            'items_total' => 145000, 'delivery_price' => 30000, 'total' => 175000,
            'status' => Order::STATUS_PAID, 'paid_at' => now()->subDays(3), 'consent_at' => now(), 'access_token' => Str::random(48),
        ], $overrides));
        $foam = OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $this->foam->id, 'sku' => $this->foam->sku,
            'title' => 'Мультипенка', 'option_label' => '200 мл', 'unit_price' => 33334, 'quantity' => 3, 'total' => 100000]);
        OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $this->cream->id, 'sku' => $this->cream->sku,
            'title' => 'Крем', 'option_label' => '50 мл', 'unit_price' => 45000, 'quantity' => 1, 'total' => 45000]);
        for ($i = 1; $i <= 3; $i++) {
            OrderItemMark::create(['order_id' => $order->id, 'order_item_id' => $foam->id, 'gtin' => '04870216352462',
                'serial' => 'S'.$order->id.'x'.$i, 'code' => '0104870216352462215S'.$order->id.'x'.$i."\x1D93AB".$i.'C', 'scanned_by' => 'Мария']);
        }
        $order->payments()->create(['gateway' => $gateway, 'idempotence_key' => (string) Str::uuid(), 'status' => Payment::STATUS_SUCCEEDED,
            'amount' => 175000, 'external_id' => $gateway === 'tochka' ? 'op-'.$order->id.'-0000-aaaa' : 'pay-'.$order->id]);

        return $order->fresh();
    }

    private function delivered(Order $order, $at = null): Order
    {
        $at ??= now()->subDays(2);
        $order->forceFill(['fulfillment_status' => Fulfillment::DELIVERED, 'fulfillment_status_at' => $at])->save();
        $event = OrderFulfillmentEvent::create(['order_id' => $order->id, 'status' => Fulfillment::DELIVERED, 'source' => OrderFulfillmentEvent::SOURCE_MANUAL]);
        $event->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $order->fresh();
    }

    private function foamItem(Order $order): OrderItem
    {
        return $order->items->firstWhere('product_variant_id', $this->foam->id);
    }

    private function creamItem(Order $order): OrderItem
    {
        return $order->items->firstWhere('product_variant_id', $this->cream->id);
    }

    private function fakeTochka(Order $order, ?int &$sent = null): void
    {
        $id = $order->payments()->first()->external_id;
        $sent = 0;
        Http::fake([
            self::TOCHKA.'acquiring/v1.0/payments/'.$id.'/refund' => function (Request $request) use (&$sent, $id) {
                $sent = (int) round(((float) $request['Data']['amount']) * 100);

                return Http::response(['Data' => ['isRefund' => true, 'operationId' => $id, 'amount' => $sent / 100, 'orderId' => 'refund-1']]);
            },
            self::TOCHKA.'acquiring/v1.0/payments/'.$id => Http::response(['Data' => ['Operation' => [[
                'customerCode' => '300123123', 'operationId' => $id, 'status' => 'APPROVED', 'amount' => 1750, 'Order' => [],
            ]]]]),
        ]);
    }

    // ------------------------------------------------------------ покупатель

    public function test_delivered_order_offers_return_and_undelivered_does_not(): void
    {
        $user = User::factory()->create();
        $order = $this->delivered($this->order(user: $user));
        $waiting = $this->order(user: $user);

        $this->actingAs($user)->get(route('order.show', $order->number))->assertOk()->assertSee('Вернуть заказ')
            ->assertSee('семь дней со дня получения');
        $this->get(route('account'))->assertOk()->assertSee(route('order.return', ['order' => $order->number]), false);
        $this->get(route('order.return', ['order' => $order->number]))->assertOk()
            ->assertSee('Мультипенка, 200 мл')->assertSee('Крем, 50 мл')->assertSee('Не подошёл')->assertSee('Прислали не тот товар')
            ->assertSee('условиями возврата');

        $this->get(route('order.show', $waiting->number))->assertOk()->assertDontSee('Вернуть заказ');
        $this->get(route('order.return', ['order' => $waiting->number]))->assertRedirect();
    }

    public function test_after_seven_days_only_defects_can_be_claimed(): void
    {
        $user = User::factory()->create();
        $order = $this->delivered($this->order(user: $user), now()->subDays(9));
        $rules = app(ReturnService::class)->eligibility($order);
        $this->assertTrue($rules['allowed']);
        $this->assertSame(OrderReturn::sellerReasons(), $rules['reasons']);

        $this->actingAs($user)->post(route('order.return.store', ['order' => $order->number]), [
            'qty' => [$this->creamItem($order)->id => 1], 'reason' => OrderReturn::REASON_UNFIT, 'conditions' => '1',
        ])->assertSessionHasErrors('reason');
        $this->assertSame(0, OrderReturn::count());

        $this->post(route('order.return.store', ['order' => $order->number]), [
            'qty' => [$this->creamItem($order)->id => 1], 'reason' => OrderReturn::REASON_DEFECT, 'comment' => 'Крем расслоился', 'conditions' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(OrderReturn::PAYER_SELLER, OrderReturn::sole()->shipping_payer);
    }

    public function test_customer_asks_to_return_part_of_the_order_with_a_photo(): void
    {
        $user = User::factory()->create();
        $order = $this->delivered($this->order(user: $user));

        $this->actingAs($user)->post(route('order.return.store', ['order' => $order->number]), [
            'qty' => [$this->foamItem($order)->id => 1, $this->creamItem($order)->id => 0], 'reason' => OrderReturn::REASON_UNFIT,
            'photos' => [UploadedFile::fake()->image('foam.jpg', 800, 600)], 'conditions' => '1',
        ])->assertRedirect(route('order.show', $order->number))->assertSessionHas('status');

        $return = OrderReturn::sole();
        $this->assertSame($order->number.'-R1', $return->number);
        $this->assertSame(OrderReturn::REQUESTED, $return->status);
        $this->assertTrue($return->partial);
        // Возвращается последняя штука строки — ей достался остаток от деления.
        $this->assertSame(33334, (int) $return->goods_amount);
        $this->assertSame(OrderReturn::PAYER_BUYER, $return->shipping_payer);
        $this->assertCount(1, $return->photos);
        Storage::disk('local')->assertExists($return->photos[0]);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'requested' && $mail->hasTo('anna@example.com'));
        Mail::assertQueued(OrderReturnManagerNotice::class, fn (OrderReturnManagerNotice $mail) => $mail->hasTo('boss@livsi.test'));

        // Вторая заявка, пока первая в работе, — нельзя.
        $this->post(route('order.return.store', ['order' => $order->number]), [
            'qty' => [$this->creamItem($order)->id => 1], 'reason' => OrderReturn::REASON_UNFIT, 'conditions' => '1',
        ])->assertRedirect();
        $this->assertSame(1, OrderReturn::count());
        $this->get(route('order.show', $order->number))->assertSee('Заявка на возврат на рассмотрении')->assertSee('Отозвать заявку');

        // Фото — только админке.
        $this->get(route('admin.returns.photo', ['return' => $return, 'index' => 0]))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.returns.photo', ['return' => $return, 'index' => 0]))->assertOk();
    }

    public function test_guest_returns_by_the_link_from_the_letter_and_can_withdraw(): void
    {
        $order = $this->delivered($this->order());

        $this->get(route('order.return', ['order' => $order->number, 'token' => 'wrong']))->assertNotFound();
        $this->get(route('order.return', ['order' => $order->number, 'token' => $order->access_token]))->assertOk()
            ->assertSee('name="token" value="'.$order->access_token.'"', false);
        $this->post(route('order.return.store', ['order' => $order->number]), [
            'token' => $order->access_token, 'qty' => [$this->creamItem($order)->id => 1],
            'reason' => OrderReturn::REASON_WRONG, 'comment' => 'Пришёл другой объём', 'conditions' => '1',
        ])->assertRedirect($order->accessUrl());
        $this->assertSame(OrderReturn::REQUESTED, OrderReturn::sole()->status);

        $this->post(route('order.return.cancel', ['order' => $order->number]), ['token' => $order->access_token])->assertRedirect();
        $this->assertSame(OrderReturn::CANCELLED, OrderReturn::sole()->status);
        $this->assertTrue(app(ReturnService::class)->eligibility($order->fresh())['allowed']);
    }

    public function test_comment_and_conditions_are_required(): void
    {
        $order = $this->delivered($this->order());
        $this->post(route('order.return.store', ['order' => $order->number]), [
            'token' => $order->access_token, 'qty' => [$this->creamItem($order)->id => 1], 'reason' => OrderReturn::REASON_DEFECT,
        ])->assertSessionHasErrors(['comment', 'conditions']);
        $this->post(route('order.return.store', ['order' => $order->number]), [
            'token' => $order->access_token, 'qty' => [$this->creamItem($order)->id => 0], 'reason' => OrderReturn::REASON_UNFIT, 'conditions' => '1',
        ])->assertSessionHasErrors('items');
        $this->assertSame(0, OrderReturn::count());
    }

    // ------------------------------------------------------------ магазин

    public function test_courier_return_of_one_piece_refunds_its_price_minus_the_trip(): void
    {
        $order = $this->delivered($this->order());
        $return = app(ReturnService::class)->request($order, [$this->foamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        // В списке заказов заявка видна сразу, есть вкладка «Возвраты».
        Livewire::test(ListOrders::class)->assertSee('Клиент просит вернуть')->assertSee('Возвраты');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Клиент просит вернуть заказ')
            ->assertActionVisible('approveReturn')->assertActionVisible('rejectReturn')->assertActionHidden('refundReturn')
            ->callAction('approveReturn', ['method' => OrderReturn::METHOD_COURIER, 'payer' => OrderReturn::PAYER_BUYER,
                'shipping_cost' => '150', 'instructions' => 'Курьер позвонит завтра до обеда.'])
            ->assertHasNoActionErrors();
        $return->refresh();
        $this->assertSame([OrderReturn::APPROVED, OrderReturn::METHOD_COURIER, 15000, 18334],
            [$return->status, $return->method, (int) $return->shipping_cost, $return->expectedRefund()]);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('rejectReturn')->assertActionVisible('receiveReturn')
            ->callAction('receiveReturn', ['restock' => 'yes', 'note' => 'Плёнка цела'])
            ->assertHasNoActionErrors();
        $this->assertSame(OrderReturn::RECEIVED, $return->fresh()->status);
        $this->assertFalse(ReturnService::canDecide($return->fresh()));
        $this->assertSame(2, (int) StockQuota::where('product_variant_id', $this->foam->id)->value('sold'));
        $this->assertSame(1, (int) StockQuota::where('product_variant_id', $this->cream->id)->value('sold'));

        $this->fakeTochka($order, $sent);
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('refundReturn')
            ->callAction('refundReturn', ['amount' => '183.34'])
            ->assertHasNoActionErrors();

        $this->assertSame(18334, $sent);
        $return->refresh();
        $this->assertSame([OrderReturn::REFUNDED, 18334], [$return->status, (int) $return->refund_amount]);
        $order->refresh();
        // Часть заказа: заказ остаётся оплаченным, освобождён код только вернувшейся штуки.
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(['S'.$order->id.'x1', 'S'.$order->id.'x2'], $order->marks()->orderBy('id')->pluck('serial')->all());
        $refund = $order->receipt(OrderReceipt::KIND_REFUND);
        $this->assertSame($return->id, $refund->payload['return_id']);
        $this->assertSame(['04870216352462 S'.$order->id.'x3'], $refund->payload['released_marks']);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'refunded');
        $this->get($order->accessUrl())->assertSee('Деньги возвращены: 183,34');
        // Чек возврата пробила Точка без КИЗ — код вернувшейся штуки и подсказка, как вернуть его в оборот вручную.
        $this->assertSame(['010487021635246221S'.$order->id.'x3'], $return->marks_to_return);
        $this->assertTrue($return->marksPending());
        Livewire::test(ListOrders::class)->assertSee('Возврат: код в оборот (ЧЗ)');
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Коды нужно вернуть в оборот вручную')->assertSee('010487021635246221S'.$order->id.'x3')
            ->assertSee('Возврат при розничной реализации')->assertSee('Возврат прихода')
            ->assertActionHidden('refundReturn')->assertActionHidden('approveReturn')
            ->assertActionVisible('marksReturned')
            ->callAction('marksReturned')
            ->assertHasNoActionErrors();
        $this->assertNotNull($return->fresh()->marks_returned_at);
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Коды возвращены в оборот')->assertActionHidden('marksReturned');
        Livewire::test(ListOrders::class)->assertDontSee('Возврат: код в оборот (ЧЗ)');
        $this->assertFalse(app(ReturnService::class)->eligibility($order)['allowed']);
    }

    public function test_full_return_of_a_defect_refunds_delivery_and_closes_the_order(): void
    {
        $order = $this->delivered($this->order());
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->foamItem($order)->id => 3, $this->creamItem($order)->id => 1],
            OrderReturn::REASON_DEFECT, 'Пенка не пенится', null);
        $this->assertFalse($return->partial);
        $return = $service->approve($return, ['method' => OrderReturn::METHOD_MANUAL, 'payer' => OrderReturn::PAYER_SELLER,
            'refund_delivery' => true, 'instructions' => 'Привезите в шоурум на Доваторцев, 84.'], 'admin');
        $this->assertSame(175000, $return->expectedRefund());
        $return = $service->receive($return, false, 'Вскрыт', 'admin');
        $this->fakeTochka($order, $sent);

        $return = $service->refund($return, $return->expectedRefund(), 'admin');

        $this->assertSame(175000, $sent);
        $this->assertSame(OrderReturn::REFUNDED, $return->status);
        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame(0, $order->marks()->count());
        // В продажу не вернули — склад не тронут, «принят на склад» можно нажать позже.
        $this->assertSame(3, (int) StockQuota::where('product_variant_id', $this->foam->id)->value('sold'));
        $this->assertNull($order->returned_to_stock_at);
        $this->assertTrue($order->canReturnToStock());
    }

    public function test_cdek_waybill_is_a_client_return_to_our_point(): void
    {
        $order = $this->delivered($this->order());
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'type' => 'pvz', 'name' => 'На Тверской', 'address' => 'Тверская, 1',
            'city' => 'Москва', 'city_key' => 'москва', 'limits' => ['city_code' => 44, 'reception' => true], 'is_active' => true]);
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'STV7', 'type' => 'pvz', 'name' => 'Доваторцев', 'address' => 'Доваторцев, 84',
            'city' => 'Ставрополь', 'city_key' => 'ставрополь', 'limits' => ['city_code' => 434], 'is_active' => true]);
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $created = 0;
        $status = ['CREATED', 'Создан'];
        Http::fake([
            self::CDEK.'oauth/token*' => Http::response(['access_token' => 'cdek-token', 'expires_in' => 3600]),
            self::CDEK.'orders/uuid-ret-1' => function () use (&$status) {
                return Http::response(['entity' => ['uuid' => 'uuid-ret-1', 'cdek_number' => '1234567890',
                    'delivery_detail' => ['total_sum' => 350.5],
                    'statuses' => [['code' => $status[0], 'name' => $status[1], 'date_time' => now()->toIso8601String()]]]]);
            },
            self::CDEK.'orders' => function () use (&$created) {
                $created++;

                return Http::response(['entity' => ['uuid' => 'uuid-ret-1'], 'requests' => [['state' => 'ACCEPTED']]], 202);
            },
        ]);

        try {
            $service->approve($return, ['method' => OrderReturn::METHOD_CDEK, 'payer' => OrderReturn::PAYER_BUYER, 'cdek_point' => 'STV7'], 'admin');
            $this->fail('Из нашего пункта приёма в него же накладная не нужна.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('приходят возвраты', $exception->validator->errors()->first());
        }
        $this->assertSame(0, $created);

        $return = $service->approve($return, ['method' => OrderReturn::METHOD_CDEK, 'payer' => OrderReturn::PAYER_BUYER, 'cdek_point' => 'MSK305'], 'admin');

        $this->assertSame(1, $created);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders')
            && $request['is_client_return'] === true && $request['type'] === 1 && $request['tariff_code'] === 136
            && $request['number'] === $order->number.'-R1' && $request['shipment_point'] === 'MSK305' && $request['delivery_point'] === 'STV7'
            && $request['sender']['phones'][0]['number'] === '+79001112233' && $request['recipient']['name'] === 'ИП Волкодав'
            && $request['packages'][0]['items'][0]['amount'] === 1 && (float) $request['packages'][0]['items'][0]['cost'] === 450.0);
        $this->assertSame(['1234567890', 35050, 'created'], [$return->shipment_number, (int) $return->shipping_cost, $return->shipment_state]);
        $this->assertSame(45000 - 35050, $return->expectedRefund());
        $this->assertSame('Москва, Тверская, 1', $return->cdek_point_address);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved'
            && str_contains($mail->render(), '1234567890') && str_contains($mail->render(), 'клиентский возврат'));
        $this->assertStringContainsString('1234567890', $return->customerStatus());

        // Посылка пришла в наш пункт — сообщить один раз.
        $status = ['ACCEPTED_AT_PICK_UP_POINT', 'Принят на склад до востребования'];
        $return = $service->refreshWaybill($return);
        $this->assertSame('ACCEPTED_AT_PICK_UP_POINT', $return->shipment_status_code);
        $this->assertTrue($return->arrived());
        $this->assertNotNull($return->arrived_notified_at);
        $this->assertSame(35050, (int) $return->shipping_cost);
    }

    public function test_lost_cdek_answer_is_found_by_number_not_made_twice(): void
    {
        $order = $this->delivered($this->order());
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'type' => 'pvz', 'name' => 'На Тверской', 'address' => 'Тверская, 1',
            'city' => 'Москва', 'city_key' => 'москва', 'limits' => ['city_code' => 44], 'is_active' => true]);
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $created = 0;
        Http::fake([
            self::CDEK.'oauth/token*' => Http::response(['access_token' => 'cdek-token', 'expires_in' => 3600]),
            self::CDEK.'orders?im_number=*' => Http::response(['entity' => ['uuid' => 'uuid-ret-2', 'cdek_number' => '555',
                'statuses' => [['code' => 'CREATED', 'name' => 'Создан', 'date_time' => now()->toIso8601String()]]]]),
            self::CDEK.'orders' => function () use (&$created) {
                $created++;
                throw new ConnectionException('timeout');
            },
        ]);

        $return = $service->approve($return, ['method' => OrderReturn::METHOD_CDEK, 'payer' => OrderReturn::PAYER_BUYER, 'cdek_point' => 'MSK305'], 'admin');
        $this->assertSame([OrderReturn::APPROVED, 'unknown'], [$return->status, $return->shipment_state]);
        Mail::assertNotQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved');

        $return = $service->createWaybill($return);

        $this->assertSame(1, $created);
        $this->assertSame(['uuid-ret-2', '555'], [$return->shipment_uuid, $return->shipment_number]);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved');
    }

    public function test_point_refused_by_cdek_can_be_switched_to_courier(): void
    {
        $order = $this->delivered($this->order());
        DeliveryPoint::create(['provider' => 'cdek', 'external_id' => 'MSK305', 'type' => 'pvz', 'name' => 'На Тверской', 'address' => 'Тверская, 1',
            'city' => 'Москва', 'city_key' => 'москва', 'limits' => ['city_code' => 44], 'is_active' => true]);
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        Http::fake([
            self::CDEK.'oauth/token*' => Http::response(['access_token' => 'cdek-token', 'expires_in' => 3600]),
            self::CDEK.'orders' => Http::response(['requests' => [['state' => 'INVALID', 'errors' => [['code' => 'x', 'message' => 'Пункт не принимает отправления']]]]], 400),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $return = $service->approve($return, ['method' => OrderReturn::METHOD_CDEK, 'payer' => OrderReturn::PAYER_BUYER, 'cdek_point' => 'MSK305'], 'admin');
        $this->assertSame('failed', $return->shipment_state);
        $this->assertStringContainsString('Пункт не принимает', $return->shipment_error);
        $this->assertTrue(ReturnService::canDecide($return));

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('approveReturn')->assertActionVisible('returnWaybill')
            ->callAction('approveReturn', ['method' => OrderReturn::METHOD_MANUAL, 'payer' => OrderReturn::PAYER_BUYER,
                'shipping_cost' => '200', 'instructions' => 'Отправьте Почтой России на адрес склада.'])
            ->assertHasNoActionErrors();
        $return->refresh();
        $this->assertSame([OrderReturn::METHOD_MANUAL, null, 20000], [$return->method, $return->shipment_state, (int) $return->shipping_cost]);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved' && str_contains($mail->render(), 'Почтой России'));
    }

    public function test_free_return_trip_is_not_promised_as_a_deduction(): void
    {
        $order = $this->delivered($this->order());
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $service->approve($return, ['method' => OrderReturn::METHOD_COURIER, 'payer' => OrderReturn::PAYER_BUYER, 'shipping_cost' => 0], 'admin');

        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'approved'
            && str_contains($mail->render(), 'за наш счёт') && ! str_contains($mail->render(), 'удержим'));
    }

    public function test_reject_explains_and_the_customer_may_ask_again(): void
    {
        $order = $this->delivered($this->order());
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('rejectReturn', ['reason' => 'На фото крем вскрыт и использован.'])
            ->assertHasNoActionErrors();

        $this->assertSame(OrderReturn::REJECTED, $return->fresh()->status);
        Mail::assertQueued(OrderReturnMail::class, fn (OrderReturnMail $mail) => $mail->kind === 'rejected'
            && str_contains($mail->render(), 'вскрыт и использован'));
        $this->assertTrue($service->eligibility($order->fresh())['allowed']);
        $this->get($order->accessUrl())->assertSee('В возврате отказано: На фото крем вскрыт и использован.');
    }

    public function test_yookassa_payment_is_returned_with_the_full_refund_button(): void
    {
        $order = $this->delivered($this->order([], 'yookassa'));
        $service = app(ReturnService::class);
        $return = $service->request($order, [$this->creamItem($order)->id => 1], OrderReturn::REASON_UNFIT, null, null);
        $return = $service->approve($return, ['method' => OrderReturn::METHOD_MANUAL, 'payer' => OrderReturn::PAYER_BUYER,
            'instructions' => 'Отправьте Почтой России.'], 'admin');
        $return = $service->receive($return, true, null, 'admin');

        try {
            $service->refund($return, 45000, 'admin');
            $this->fail('ЮKassa не должна возвращать частично отсюда.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Отменить заказ и вернуть деньги', $exception->validator->errors()->first());
        }
        $this->assertSame(OrderReturn::RECEIVED, $return->fresh()->status);
        $this->assertStringContainsString('ЮKassa', $return->fresh()->refund_error);
    }

    public function test_delivered_time_is_not_moved_by_journal_notes(): void
    {
        $order = $this->delivered($this->order(), now()->subDays(3));
        OrderFulfillmentEvent::create(['order_id' => $order->id, 'status' => Fulfillment::DELIVERED, 'source' => OrderFulfillmentEvent::SOURCE_MANUAL,
            'comment' => 'Возврат: товар получен']);

        $step = collect(Fulfillment::steps($order->fresh()))->firstWhere('status', Fulfillment::DELIVERED);

        $this->assertTrue($step['time']->lt(now()->subDays(2)));
        $this->assertTrue(app(ReturnService::class)->deliveredAt($order->fresh())->lt(now()->subDays(2)));
    }
}
