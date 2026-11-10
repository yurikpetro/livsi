<?php

namespace App\Http\Controllers\Livsipro;

use App\Delivery\DirectShipmentService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\OrderItemMark;
use App\Models\OrderReceipt;
use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Services\DeliverySelection;
use App\Services\FulfillmentService;
use App\Services\ReceiptService;
use App\Services\ShipmentService;
use App\Services\StockReturnService;
use App\Support\Fulfillment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Заказы сайта для вкладки «Сайт» на livsipro.ru.
 *
 * Отдаёт только то, что нужно сборщику: состав, пункт выдачи, имя
 * получателя (оно же на этикетке), статусы, а в карточке заказа — ещё
 * оплату, чеки и суммы, чтобы владелец не ходил за ними в админку.
 * Телефон и почта покупателя сюда не попадают — для сборки они не нужны
 * (исключение — курьер по Ставрополю: ему телефон нужен).
 *
 * Каждое действие подписывается именем сборщика (поле by): оно уходит в
 * журнал заказа на сайте, и видно, кто собрал и кто передал.
 */
class OrdersController extends Controller
{
    public function __construct(
        private readonly FulfillmentService $fulfillment,
        private readonly DirectShipmentService $shipments,
    ) {}

    /**
     * Список по разделам: assembly — ждут сборки и собраны; shipped — в пути;
     * returns — не забрали или вернули после получения, ждут приёмки на склад;
     * done — получены, отменены до отправки, возвраты приняты; all — все.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['sometimes', 'in:assembly,shipped,returns,done,all'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $view = $data['view'] ?? 'assembly';
        $query = $this->section($view)->withCount('items')->latest('paid_at');

        return response()->json([
            'orders' => $query->limit($data['limit'] ?? 200)->get()->map(fn (Order $order): array => $this->brief($order))->all(),
            'counts' => [
                'awaiting' => Order::query()->where('status', Order::STATUS_PAID)->withoutRefund()
                    ->where(fn ($q) => $q->whereNull('fulfillment_status')->orWhere('fulfillment_status', Fulfillment::AWAITING))->count(),
                'assembled' => Order::query()->where('status', Order::STATUS_PAID)->withoutRefund()->where('fulfillment_status', Fulfillment::ASSEMBLED)->count(),
                'shipped' => $this->section('shipped')->count(),
                'returns' => $this->section('returns')->count(),
            ],
        ]);
    }

    /** Заказы раздела livsipro. */
    private function section(string $view): Builder
    {
        $paid = fn () => Order::query()->where('status', Order::STATUS_PAID)->withoutRefund();
        $left = [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP, Fulfillment::DELIVERED];

        return match ($view) {
            'assembly' => $paid()->where(fn ($q) => $q->whereNull('fulfillment_status')
                ->orWhereIn('fulfillment_status', [Fulfillment::AWAITING, Fulfillment::ASSEMBLED])),
            'shipped' => $paid()->whereIn('fulfillment_status', [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP]),
            'returns' => Order::query()->whereIn('status', [Order::STATUS_PAID, Order::STATUS_REFUNDED])->whereNull('returned_to_stock_at')
                ->where(fn ($q) => $q->where('fulfillment_status', Fulfillment::RETURNED)
                    ->orWhere(fn ($q) => $q->where('status', Order::STATUS_REFUNDED)->whereIn('fulfillment_status', $left))),
            'done' => Order::query()->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Order::STATUS_PAID)->where('fulfillment_status', Fulfillment::DELIVERED))
                ->orWhereNotNull('returned_to_stock_at')
                ->orWhere(fn ($q) => $q->where('status', Order::STATUS_REFUNDED)->whereNull('fulfillment_status'))),
            default => Order::query()->whereIn('status', [Order::STATUS_PAID, Order::STATUS_REFUNDED]),
        };
    }

    public function show(string $number): JsonResponse
    {
        return response()->json(['order' => $this->full($this->order($number, false))]);
    }

    /** «Принят на склад» — вернувшаяся посылка проверена, товар снова в продаже. */
    public function restock(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = app(StockReturnService::class)->accept($this->order($number, false), $by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** Отсканированный КИЗ. Разбор кода делает livsipro, здесь — сверка и запись. */
    public function addMark(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'gtin' => ['required', 'regex:/^[0-9]{14}$/'],
            'serial' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
            'code' => ['required', 'string', 'min:18', 'max:300'],
            'by' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $order = $this->order($number);
        $this->assertAssembling($order);
        $item = $order->items()->whereKey($data['item_id'])->with('variant')->first();
        if (! $item) {
            $this->fail('Такой позиции в заказе нет.');
        }
        $clean = str_replace("\x1D", '', $data['code']);
        if (! str_starts_with($clean, '01'.$data['gtin'].'21'.$data['serial'])) {
            $this->fail('Код маркировки прочитан не полностью. Отсканируйте ещё раз.');
        }
        $expected = $this->gtin($item);
        if ($expected !== null && $expected !== $data['gtin']) {
            $this->fail('Это код другого товара: в заказе «'.$item->label().'».');
        }

        try {
            DB::transaction(function () use ($order, $item, $data): void {
                Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($item->marks()->count() >= $item->quantity) {
                    $this->fail('По «'.$item->label().'» уже отсканировано '.$item->quantity.' шт. — больше не нужно.');
                }
                OrderItemMark::create([
                    'order_id' => $order->id, 'order_item_id' => $item->id,
                    'gtin' => $data['gtin'], 'serial' => $data['serial'], 'code' => $data['code'],
                    'scanned_by' => $data['by'],
                ]);
            });
        } catch (QueryException $exception) {
            $other = OrderItemMark::query()->where('gtin', $data['gtin'])->where('serial', $data['serial'])->with('order')->first();
            $this->fail($other && $other->order_id !== $order->id
                ? 'Этот код уже ушёл в заказ '.$other->order?->number.'. Возьмите другой экземпляр.'
                : 'Этот код уже отсканирован в этом заказе.');
        }

        return response()->json(['order' => $this->full($order->fresh())]);
    }

    public function removeMark(string $number, int $mark): JsonResponse
    {
        $order = $this->order($number);
        $this->assertAssembling($order);
        $order->marks()->whereKey($mark)->delete();

        return response()->json(['order' => $this->full($order->fresh())]);
    }

    /** «Собран»: все позиции на месте, коды по каждой штуке. */
    public function assembled(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = $this->order($number);
        if (config('livsi.livsipro.require_marks', true)) {
            foreach ($order->items()->withCount('marks')->get() as $item) {
                if ($item->marks_count < $item->quantity) {
                    $this->fail('Не хватает кодов маркировки: «'.$item->label().'» — '.$item->marks_count.' из '.$item->quantity.'.');
                }
            }
        }
        $order = $this->fulfillment->set($order, Fulfillment::ASSEMBLED, 'Сборщик: '.$by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** Создать отправление у службы — после сборки, перед печатью этикетки. */
    public function shipment(Request $request, string $number): JsonResponse
    {
        $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']]);
        $order = $this->order($number);
        if (! DirectShipmentService::isDirectOrder($order)) {
            $this->fail('Заказ оформлен через ApiShip — отправление создаётся в админке сайта.');
        }
        if (Fulfillment::current($order) === Fulfillment::AWAITING) {
            $this->fail('Сначала отметьте заказ собранным.');
        }
        // Отправитель — как в админке: реквизиты продавца и телефон склада
        // из настроек сайта; в .env можно задать своё имя и телефон.
        $defaults = app(ShipmentService::class)->senderDefaults();
        $order = $order->shipment_external_id
            ? $this->shipments->refresh($order)
            : $this->shipments->create($order, [
                'name' => (string) (config('livsi.livsipro.sender_name') ?: $defaults['name']),
                'phone' => (string) (config('livsi.livsipro.sender_phone') ?: $defaults['phone']),
                'email' => $defaults['email'],
            ]);

        return response()->json(['order' => $this->full($order->fresh())]);
    }

    public function label(Request $request, string $number): Response
    {
        $order = $this->order($number);
        try {
            $pdf = $this->shipments->label($order, (string) $request->query('size', '75x120'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail('Служба не отдала этикетку. Повторите через минуту.');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->number.'.pdf"',
        ]);
    }

    /** «Передан в доставку» — отдали курьеру или сдали в пункт приёма. */
    public function shipped(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = $this->order($number);
        if (DirectShipmentService::isDirectOrder($order) && ! $order->shipment_external_id) {
            $this->fail('Сначала создайте отправление и наклейте этикетку.');
        }
        if (! in_array(Fulfillment::current($order), [Fulfillment::ASSEMBLED, Fulfillment::SHIPPED], true)) {
            $this->fail('Передать можно только собранный заказ.');
        }
        $order = $this->fulfillment->set($order, Fulfillment::SHIPPED, 'Передал: '.$by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** Курьер по Ставрополю отдал заказ покупателю. Службы тут нет — отметку ставит сборщик. */
    public function delivered(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = $this->order($number);
        if (! Fulfillment::isCourier($order)) {
            $this->fail('«Доставлен» ставит служба доставки сама — отмечать вручную нужно только курьера по Ставрополю.');
        }
        if (Fulfillment::current($order) !== Fulfillment::SHIPPED) {
            $this->fail('Сначала отметьте, что заказ передан курьеру.');
        }
        $order = $this->fulfillment->set($order, Fulfillment::DELIVERED, 'Курьер доставил, отметил: '.$by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** «Вернуть в сборку»: «Собран» нажали по ошибке — пока отправление не создано. */
    public function reassemble(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = $this->order($number);
        if (Fulfillment::current($order) !== Fulfillment::ASSEMBLED) {
            $this->fail('Вернуть в сборку можно только собранный, но ещё не переданный заказ.');
        }
        if ($order->shipment_external_id || in_array($order->shipment_state, ['creating', 'unknown'], true)) {
            $this->fail('Сначала отмените отправление у службы.');
        }
        $order = $this->fulfillment->set($order, Fulfillment::AWAITING, 'Вернул в сборку: '.$by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** «Отменить отправление»: снять заявку у службы, пока посылка не передана. */
    public function cancelShipment(Request $request, string $number): JsonResponse
    {
        $by = $request->validate(['by' => ['required', 'string', 'min:2', 'max:120']])['by'];
        $order = $this->order($number);
        if (! DirectShipmentService::isDirectOrder($order)) {
            $this->fail('Заказ оформлен через ApiShip — заявку отменяют в кабинете ApiShip.');
        }
        $order = $this->shipments->cancel($order, 'Отменил: '.$by, OrderFulfillmentEvent::SOURCE_LIVSIPRO);

        return response()->json(['order' => $this->full($order)]);
    }

    /** Спросить службу о статусе прямо сейчас, не дожидаясь расписания. */
    public function refresh(string $number): JsonResponse
    {
        $order = $this->order($number, false);
        if (! $order->shipment_external_id && ! in_array($order->shipment_state, ['creating', 'unknown'], true)) {
            return response()->json(['order' => $this->full($order)]);
        }

        return response()->json(['order' => $this->full($this->shipments->refresh($order))]);
    }

    /** Заказ по номеру. working — для сборки и отправки: только оплаченный и без возврата. */
    private function order(string $number, bool $working = true): Order
    {
        $order = Order::query()->where('number', $number)->whereIn('status', [Order::STATUS_PAID, Order::STATUS_REFUNDED])->first();
        abort_if(! $order, 404, 'Заказ не найден или не оплачен.');
        abort_if($working && ($order->status !== Order::STATUS_PAID || Order::query()->whereKey($order->id)->withoutRefund()->doesntExist()), 409,
            'По заказу оформлен возврат денег — собирать и отправлять его не нужно.');

        return $order;
    }

    /**
     * Коды можно сканировать и убирать, пока заказ не получен: при сборке и в
     * пути. Созданное отправление этому не мешает — сайт создаёт его сам сразу
     * после оплаты, ещё до сборки. А в пути коды дописывают, если при сборке
     * их не приняли (так было с заказами 17 и 18): коды нужны для чека и
     * вывода из оборота, и отправляются они при получении.
     */
    private function assertAssembling(Order $order): void
    {
        if (! self::marksEditable($order)) {
            $this->fail('Заказ уже получен или вернулся — коды менять нельзя.');
        }
    }

    private static function marksEditable(Order $order): bool
    {
        return in_array(Fulfillment::current($order), [Fulfillment::AWAITING, Fulfillment::ASSEMBLED, Fulfillment::SHIPPED, Fulfillment::AT_PICKUP], true);
    }

    /** GTIN товара из штрих-кода варианта: EAN-13 → 14 знаков с нулём впереди. */
    private function gtin(OrderItem $item): ?string
    {
        $barcode = preg_replace('/\D/', '', (string) $item->variant?->barcode);

        return match (strlen($barcode)) {
            13 => '0'.$barcode,
            14 => $barcode,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function brief(Order $order): array
    {
        $status = Fulfillment::current($order);

        return [
            'number' => $order->number,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'customer' => $order->customer_name,
            'items_count' => (int) ($order->items_count ?? $order->items()->count()),
            'total' => (int) $order->total,
            'fulfillment' => ['code' => $status, 'label' => Fulfillment::statuses()[$status] ?? $status,
                'at' => $order->fulfillment_status_at?->toIso8601String()],
            'status' => ['code' => $order->status, 'label' => $order->statusLabel()],
            'outcome' => $order->outcome(),
            'promo' => $order->promo_code ? ['code' => $order->promo_code, 'discount' => (int) $order->promo_discount,
                'free_delivery' => (bool) $order->promo_free_delivery] : null,
            'refund' => ($refund = $order->receipt(OrderReceipt::KIND_REFUND)) && in_array($refund->status, ['pending', 'succeeded'], true)
                ? ['amount' => (int) $refund->amount, 'kept_delivery' => (int) ($refund->payload['kept_delivery'] ?? 0), 'state' => $refund->status]
                : null,
            'returned_to_stock' => $order->returned_to_stock_at
                ? ['at' => $order->returned_to_stock_at->toIso8601String(), 'by' => $order->returned_to_stock_by]
                : null,
            'delivery' => [
                'method' => $order->delivery_method,
                'title' => DeliverySelection::label($order->delivery_method),
                'provider' => $order->delivery_quote['provider'] ?? null,
                'direct' => DirectShipmentService::isDirectOrder($order),
                'city' => $order->delivery_city,
                'point' => $order->delivery_point_address,
            ],
            'shipment' => [
                'state' => $order->shipment_state,
                'label' => $order->shipmentLabel(),
                'reference' => $order->shipmentReference(),
                'tracking' => $order->shipment_tracking_number,
                'error' => $order->shipment_error,
                'via' => match (true) {
                    filled($order->shipment_external_id) => 'direct',
                    filled($order->apiship_order_id) => 'apiship',
                    default => null,
                },
            ],
            // Какие кнопки сейчас уместны — чтобы livsipro не гадал по статусам.
            // Курьеру нужны адрес и телефон покупателя — только для курьерских заказов.
            'courier' => Fulfillment::isCourier($order) ? ['address' => $order->delivery_address, 'phone' => $order->customer_phone,
                'name' => $order->customer_name] : null,
            'actions' => [
                'marks' => $order->status === Order::STATUS_PAID && self::marksEditable($order),
                'courier_shipped' => Fulfillment::isCourier($order) && $order->status === Order::STATUS_PAID && $status === Fulfillment::ASSEMBLED,
                'courier_delivered' => Fulfillment::isCourier($order) && $order->status === Order::STATUS_PAID && $status === Fulfillment::SHIPPED,
                'restock' => $order->canReturnToStock(),
                // Этикетку livsipro печатает только у прямого отправления службы;
                // у отправлений через ApiShip она — в кабинете ApiShip.
                'label' => filled($order->shipment_external_id) && DirectShipmentService::isDirectOrder($order),
                'reassemble' => $status === Fulfillment::ASSEMBLED && ! $order->shipment_external_id
                    && ! in_array($order->shipment_state, ['creating', 'unknown'], true),
                'cancel_shipment' => filled($order->shipment_external_id) && DirectShipmentService::isDirectOrder($order)
                    && in_array($status, [Fulfillment::AWAITING, Fulfillment::ASSEMBLED], true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function full(Order $order): array
    {
        $order->load(['items.variant', 'items.marks']);

        return [
            ...$this->brief($order),
            'comment' => $order->comment,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'title' => $item->label(),
                'quantity' => (int) $item->quantity,
                'is_gift' => (bool) $item->is_gift,
                'price' => (int) $item->unit_price,
                'total' => (int) $item->total,
                'barcode' => $item->variant?->barcode,
                'gtin' => $this->gtin($item),
                'marks' => $item->marks->map(fn (OrderItemMark $mark): array => [
                    'id' => $mark->id, 'gtin' => $mark->gtin, 'serial' => $mark->serial, 'by' => $mark->scanned_by,
                ])->all(),
            ])->all(),
            'events' => $order->fulfillmentEvents()->latest('id')->limit(30)->get()->map(fn (OrderFulfillmentEvent $event): array => [
                'status' => Fulfillment::statuses()[$event->status] ?? $event->status,
                'source' => $event->sourceLabel(),
                'comment' => $event->comment,
                'at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'created_at' => $order->created_at?->toIso8601String(),
            'money' => [
                'items' => (int) $order->items_total,
                'discount' => (int) $order->promo_discount,
                'delivery' => (int) $order->delivery_price,
                'total' => (int) $order->total,
            ],
            'delivery_info' => $this->deliveryInfo($order),
            'payment' => $this->paymentInfo($order),
            'receipts' => $this->receiptsInfo($order),
            // Возврат по заявке покупателя: что и в каком состоянии. Без контактов.
            'return' => ($return = $order->latestReturn()) && $return->status !== \App\Models\OrderReturn::CANCELLED ? [
                'number' => $return->number,
                'status' => $return->statusLabel(),
                'reason' => $return->reasonLabel(),
                'items' => $return->itemsText(),
                'method' => $return->methodLabel(),
                'cdek_number' => $return->shipment_number,
                'cdek_status' => $return->shipment_status_name,
                'at' => $return->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** Способы оплаты ЮKassa и Точки — как их назвать человеку. */
    private const PAY_METHODS = [
        'sbp' => 'СБП', 'card' => 'банковская карта', 'bank_card' => 'банковская карта',
        'tinkoff' => 'T-Pay', 'tinkoff_bank' => 'T-Pay', 'dolyame' => 'Долями',
        'yoo_money' => 'ЮMoney', 'sberbank' => 'SberPay', 'sber_loan' => 'кредит Сбера',
    ];

    /** Куда, чем и когда: то, что нужно для листа сборки. @return array<string, mixed> */
    private function deliveryInfo(Order $order): array
    {
        $quote = (array) $order->delivery_quote;
        $parcel = (array) ($quote['parcel'] ?? []);

        return [
            'tariff' => filled($quote['tariff_name'] ?? null) ? (string) $quote['tariff_name'] : null,
            'days_min' => isset($quote['days_min']) ? (int) $quote['days_min'] : null,
            'days_max' => isset($quote['days_max']) ? (int) $quote['days_max'] : null,
            'region' => $order->delivery_region,
            'point_id' => $order->delivery_point_id,
            'point_hint' => $order->delivery_point_hint,
            'address' => $order->delivery_address,
            'weight' => isset($parcel['weight']) ? (int) $parcel['weight'] : null,
            'size' => isset($parcel['length'], $parcel['width'], $parcel['height'])
                ? [(int) $parcel['length'], (int) $parcel['width'], (int) $parcel['height']] : null,
        ];
    }

    /** Последняя оплата заказа: кто принял, как и когда. @return array<string, mixed>|null */
    private function paymentInfo(Order $order): ?array
    {
        $payment = $order->payments()->where('status', Payment::STATUS_SUCCEEDED)->latest('id')->first()
            ?? $order->payments()->latest('id')->first();
        if (! $payment) {
            return null;
        }

        return [
            'provider' => PaymentSettings::providerLabel($payment->gateway === PaymentSettings::TOCHKA ? PaymentSettings::TOCHKA : PaymentSettings::YOOKASSA),
            'status' => $payment->statusLabel(),
            'paid' => $payment->status === Payment::STATUS_SUCCEEDED,
            'method' => self::PAY_METHODS[(string) $payment->payment_method] ?? ($payment->payment_method ?: null),
            'amount' => (int) $payment->amount,
            'at' => $payment->paid_at?->toIso8601String(),
        ];
    }

    /**
     * Чеки: первый (предоплата) пробивается вместе с оплатой; второй
     * «полный расчёт» с КИЗ — когда заказ получен; возврат и чек возврата.
     *
     * @return array<string, mixed>
     */
    private function receiptsInfo(Order $order): array
    {
        $receipts = app(ReceiptService::class);
        $paid = $receipts->payment($order) !== null;
        $row = function (?OrderReceipt $record): ?array {
            return $record ? [
                'state' => $record->status,
                'label' => $record->statusLabel(),
                'amount' => (int) $record->amount,
                'at' => $record->sent_at?->toIso8601String(),
                'done_at' => $record->done_at?->toIso8601String(),
                'error' => $record->error,
            ] : null;
        };
        $closing = $row($order->receipt(OrderReceipt::KIND_CLOSING)) ?? ['state' => 'none', 'label' => match (true) {
            ! $paid => 'не нужен — заказ оплачен не через сайт',
            ! $receipts->enabled() => 'второй чек выключен в настройках сайта',
            default => 'уйдёт сам, когда заказ получит статус «Получен»',
        }];

        return [
            'first' => $paid ? 'Предоплата 100 % — пробит при оплате' : null,
            'closing' => $closing,
            'refund' => $row($order->receipt(OrderReceipt::KIND_REFUND)),
            'refund_receipt' => $row($order->receipt(OrderReceipt::KIND_REFUND_RECEIPT)),
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['order' => $message]);
    }
}
