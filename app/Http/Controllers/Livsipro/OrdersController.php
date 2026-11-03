<?php

namespace App\Http\Controllers\Livsipro;

use App\Delivery\DirectShipmentService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\OrderItemMark;
use App\Services\DeliverySelection;
use App\Services\FulfillmentService;
use App\Services\ShipmentService;
use App\Support\Fulfillment;
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
 * получателя (оно же на этикетке), статусы. Телефон и почта покупателя
 * сюда не попадают — для сборки они не нужны.
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

    /** Список: view=assembly (ждут сборки и собраны), shipped (в пути), all. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['sometimes', 'in:assembly,shipped,done,all'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $view = $data['view'] ?? 'assembly';
        $query = Order::query()->where('status', Order::STATUS_PAID)->withCount('items')->latest('paid_at');
        match ($view) {
            'assembly' => $query->where(fn ($q) => $q->whereNull('fulfillment_status')
                ->orWhereIn('fulfillment_status', [Fulfillment::AWAITING, Fulfillment::ASSEMBLED])),
            'shipped' => $query->whereIn('fulfillment_status', [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP]),
            'done' => $query->whereIn('fulfillment_status', [Fulfillment::DELIVERED, Fulfillment::RETURNED]),
            'all' => null,
        };

        return response()->json([
            'orders' => $query->limit($data['limit'] ?? 200)->get()->map(fn (Order $order): array => $this->brief($order))->all(),
            'counts' => [
                'awaiting' => Order::query()->where('status', Order::STATUS_PAID)
                    ->where(fn ($q) => $q->whereNull('fulfillment_status')->orWhere('fulfillment_status', Fulfillment::AWAITING))->count(),
                'assembled' => Order::query()->where('status', Order::STATUS_PAID)->where('fulfillment_status', Fulfillment::ASSEMBLED)->count(),
                'shipped' => Order::query()->where('status', Order::STATUS_PAID)
                    ->whereIn('fulfillment_status', [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP])->count(),
            ],
        ]);
    }

    public function show(string $number): JsonResponse
    {
        return response()->json(['order' => $this->full($this->order($number))]);
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

    /** Спросить службу о статусе прямо сейчас, не дожидаясь расписания. */
    public function refresh(string $number): JsonResponse
    {
        $order = $this->order($number);
        if (! $order->shipment_external_id && ! in_array($order->shipment_state, ['creating', 'unknown'], true)) {
            return response()->json(['order' => $this->full($order)]);
        }

        return response()->json(['order' => $this->full($this->shipments->refresh($order))]);
    }

    private function order(string $number): Order
    {
        $order = Order::query()->where('number', $number)->where('status', Order::STATUS_PAID)->first();
        abort_if(! $order, 404, 'Заказ не найден или не оплачен.');

        return $order;
    }

    private function assertAssembling(Order $order): void
    {
        if (! in_array(Fulfillment::current($order), [Fulfillment::AWAITING, Fulfillment::ASSEMBLED], true) || $order->shipment_external_id) {
            $this->fail('Заказ уже передан в доставку — коды менять нельзя.');
        }
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
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['order' => $message]);
    }
}
