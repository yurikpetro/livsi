<?php

namespace App\Services;

use App\Delivery\Carriers\CdekCarrier;
use App\Delivery\DeliveryGateway;
use App\Models\DeliveryPoint;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderItem;
use App\Models\OrderReceipt;
use App\Models\OrderReturn;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Models\User;
use App\Monitoring\BusinessNotifications;
use App\Payments\ReceiptBuilder;
use App\Support\Fulfillment;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Возврат товара после получения.
 *
 * 1. Покупатель в кабинете (или по ссылке из письма) выбирает позиции,
 *    причину, пишет комментарий, прикладывает фото. Срок — 7 дней со дня
 *    получения (ЗоЗПП, ст. 26.1); позже — только брак или ошибка в заказе,
 *    решение за магазином.
 * 2. Магазин подтверждает или отказывает. При подтверждении выбирает, как
 *    товар вернётся: накладная СДЭК (покупатель сдаёт посылку в пункт СДЭК,
 *    она едет в наш пункт приёма; платим мы по договору), наш курьер или
 *    иначе. Доставку оплачивает магазин при браке, ошибке и повреждении,
 *    покупатель — если товар не подошёл: её стоимость удерживается.
 * 3. Товар пришёл — «Товар получен» (в продажу или нет), затем
 *    «Вернуть деньги» через Точку: за вернувшиеся позиции минус удержание.
 *
 * Одна заявка в работе на заказ. Деньги за заказ возвращаются один раз:
 * после возврата по заявке второй возврат — вручную.
 */
class ReturnService
{
    /** Дней на отказ от товара надлежащего качества. */
    public const WINDOW_DAYS = 7;

    /** Сколько дней после получения можно заявить о браке через сайт. */
    public const CLAIM_DAYS = 365;

    public const MAX_PHOTOS = 5;

    public const PHOTO_DISK = 'local';

    public function __construct(
        private readonly OrderMailer $mailer,
        private readonly DeliveryGateway $delivery,
    ) {}

    /** Когда покупатель получил заказ — начало последнего перехода в «Получен». */
    public function deliveredAt(Order $order): ?Carbon
    {
        if (Fulfillment::current($order) !== Fulfillment::DELIVERED) {
            return null;
        }
        $since = null;
        foreach ($order->fulfillmentEvents()->orderByDesc('id')->get(['id', 'status', 'created_at']) as $event) {
            if ($event->status !== Fulfillment::DELIVERED) {
                break;
            }
            $since = $event->created_at;
        }

        return ($since ?? $order->fulfillment_status_at)?->copy();
    }

    /**
     * Можно ли сейчас заявить возврат и с какими причинами.
     *
     * @return array{allowed: bool, reasons: list<string>, until: ?Carbon, note: ?string}
     */
    public function eligibility(Order $order): array
    {
        $no = fn (?string $note = null): array => ['allowed' => false, 'reasons' => [], 'until' => null, 'note' => $note];
        if ($order->status !== Order::STATUS_PAID) {
            return $no();
        }
        $delivered = $this->deliveredAt($order);
        if (! $delivered || $order->activeReturn()) {
            return $no();
        }
        if ($order->returns()->where('status', OrderReturn::REFUNDED)->exists()) {
            return $no('По заказу уже был возврат. Если нужно вернуть что-то ещё — напишите нам.');
        }
        $refund = $order->receipt(OrderReceipt::KIND_REFUND);
        if ($refund && in_array($refund->status, [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED], true)) {
            return $no();
        }
        if ($this->returnable($order) === []) {
            return $no();
        }
        $day = $delivered->copy()->timezone(Fulfillment::TIMEZONE)->startOfDay();
        $until = $day->copy()->addDays(self::WINDOW_DAYS)->endOfDay();
        $now = now()->timezone(Fulfillment::TIMEZONE);
        if ($now->lessThanOrEqualTo($until)) {
            return ['allowed' => true, 'reasons' => array_keys(OrderReturn::reasons()), 'until' => $until, 'note' => null];
        }
        if ($now->lessThanOrEqualTo($day->copy()->addDays(self::CLAIM_DAYS)->endOfDay())) {
            return ['allowed' => true, 'reasons' => OrderReturn::sellerReasons(), 'until' => null,
                'note' => 'Семь дней на возврат товара, который не подошёл, прошли. Заявить можно о браке, повреждении или ошибке в заказе.'];
        }

        return $no();
    }

    /**
     * Что можно вернуть: позиции не в подарок и не за ноль.
     *
     * @return list<array{item: OrderItem, max: int, prices: list<int>}>
     */
    public function returnable(Order $order): array
    {
        $order->loadMissing('items');
        $rows = [];
        foreach ($order->items as $item) {
            if ($item->is_gift || (int) $item->quantity < 1 || (int) $item->total < 1) {
                continue;
            }
            $rows[] = ['item' => $item, 'max' => (int) $item->quantity,
                'prices' => ReceiptBuilder::unitPrices((int) $item->total, (int) $item->quantity)];
        }

        return $rows;
    }

    /**
     * Заявка покупателя.
     *
     * @param  array<int|string, int|string>  $quantities  id позиции → сколько штук
     * @param  list<UploadedFile>  $photos
     */
    public function request(Order $record, array $quantities, string $reason, ?string $comment, ?User $user, array $photos = []): OrderReturn
    {
        $return = DB::transaction(function () use ($record, $quantities, $reason, $comment, $user): OrderReturn {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);
            $rules = $this->eligibility($order);
            if (! $rules['allowed']) {
                $this->fail($rules['note'] ?? 'Этот заказ сейчас нельзя вернуть через сайт. Напишите нам — разберёмся.', 'items');
            }
            if (! in_array($reason, $rules['reasons'], true)) {
                $this->fail($rules['note'] ?? 'Выберите причину возврата.', 'reason');
            }

            $items = [];
            $all = true;
            foreach ($this->returnable($order) as $row) {
                $item = $row['item'];
                $qty = max(0, min($row['max'], (int) ($quantities[$item->id] ?? 0)));
                if ($qty < $row['max']) {
                    $all = false;
                }
                if ($qty < 1) {
                    continue;
                }
                $items[] = ['item_id' => $item->id, 'label' => $item->label(), 'sku' => (string) $item->sku, 'qty' => $qty,
                    'amount' => array_sum(array_slice($row['prices'], -$qty))];
            }
            if ($items === []) {
                $this->fail('Отметьте, что хотите вернуть.', 'items');
            }
            $count = $order->returns()->count();

            return OrderReturn::create([
                'order_id' => $order->id,
                'user_id' => $user?->id,
                'number' => $order->number.'-R'.($count + 1),
                'status' => OrderReturn::REQUESTED,
                'reason' => $reason,
                'comment' => filled($comment) ? mb_substr(Text::utf8(trim($comment)), 0, 2000) : null,
                'items' => $items,
                'goods_amount' => array_sum(array_column($items, 'amount')),
                'partial' => ! $all,
                'shipping_payer' => in_array($reason, OrderReturn::sellerReasons(), true) ? OrderReturn::PAYER_SELLER : OrderReturn::PAYER_BUYER,
            ]);
        });

        $stored = [];
        foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $photo) {
            try {
                $stored[] = $photo->store('returns/'.$return->order_id, self::PHOTO_DISK);
            } catch (Throwable $exception) {
                Log::warning('Фото к возврату не сохранено.', ['return' => $return->number, 'error' => mb_substr($exception->getMessage(), 0, 200)]);
            }
        }
        if ($stored !== []) {
            $return->update(['photos' => array_values(array_filter($stored))]);
        }

        $this->mailer->returnUpdate($return, 'requested');
        $this->mailer->returnRequestedManager($return);
        app(BusinessNotifications::class)->queue('return_request', $return->id, 'issues');

        return $return->fresh();
    }

    /** Покупатель передумал — пока магазин не ответил. */
    public function cancel(OrderReturn $return): OrderReturn
    {
        $updated = DB::transaction(function () use ($return): bool {
            $fresh = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($fresh->status !== OrderReturn::REQUESTED) {
                return false;
            }
            $fresh->update(['status' => OrderReturn::CANCELLED]);

            return true;
        });
        if (! $updated) {
            $this->fail('Заявку уже рассмотрели — отозвать её на сайте нельзя. Напишите нам.', 'return');
        }

        return $return->fresh();
    }

    /** Пункт СДЭК, откуда покупатель отправит товар, по умолчанию. */
    public function defaultCdekPoint(Order $order): ?string
    {
        if (DeliverySelection::providerKey($order->delivery_method) === 'cdek' && filled($order->delivery_point_id)) {
            return (string) $order->delivery_point_id;
        }

        return null;
    }

    /** Подпись пункта СДЭК для списка и письма. */
    public static function pointLabel(DeliveryPoint $point): string
    {
        return trim($point->external_id.' · '.($point->address ?: $point->name));
    }

    /**
     * Подтвердить возврат.
     *
     * @param  array{method: string, payer: string, shipping_cost?: int|null, refund_delivery?: bool, cdek_point?: ?string, instructions?: ?string}  $data
     */
    public function approve(OrderReturn $record, array $data, string $by): OrderReturn
    {
        $method = $data['method'] ?? '';
        if (! array_key_exists($method, OrderReturn::methods())) {
            $this->fail('Выберите, как товар вернётся.', 'method');
        }
        $payer = in_array($data['payer'] ?? '', [OrderReturn::PAYER_SELLER, OrderReturn::PAYER_BUYER], true) ? $data['payer'] : null;
        if (! $payer) {
            $this->fail('Укажите, кто оплачивает обратную доставку.', 'payer');
        }
        $point = null;
        if ($method === OrderReturn::METHOD_CDEK) {
            $point = DeliveryPoint::query()->where('provider', 'cdek')->where('external_id', (string) ($data['cdek_point'] ?? ''))->first();
            if (! $point) {
                $this->fail('Выберите пункт СДЭК, куда покупатель сдаст посылку.', 'cdek_point');
            }
        }

        $return = DB::transaction(function () use ($record, $method, $payer, $point, $data, $by): OrderReturn {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
            if (! self::canDecide($return)) {
                $this->fail('Заявка уже рассмотрена: '.mb_strtolower($return->statusLabel()).'.', 'return');
            }
            $return->update([
                'status' => OrderReturn::APPROVED,
                'method' => $method,
                'shipping_payer' => $payer,
                'shipping_cost' => $method === OrderReturn::METHOD_CDEK ? 0 : max(0, (int) ($data['shipping_cost'] ?? 0)),
                'refund_delivery' => (bool) ($data['refund_delivery'] ?? false),
                'shipment_state' => null, 'shipment_error' => null,
                'cdek_point' => $point?->external_id,
                'cdek_point_address' => $point ? mb_substr(trim(($point->city ? $point->city.', ' : '').($point->address ?: $point->name)), 0, 255) : null,
                'instructions' => filled($data['instructions'] ?? null) ? mb_substr(Text::utf8(trim($data['instructions'])), 0, 2000) : null,
                'decided_at' => now(),
                'decided_by' => mb_substr($by, 0, 120),
            ]);

            return $return;
        });

        if ($method === OrderReturn::METHOD_CDEK) {
            // Письмо уйдёт, когда у СДЭК появится номер: без него покупателю нечего сказать в пункте.
            try {
                return $this->createWaybill($return);
            } catch (ValidationException $exception) {
                return $return->fresh();
            }
        }
        $this->mailer->returnUpdate($return->fresh(), 'approved');

        return $return->fresh();
    }

    /**
     * Решение можно принять — или поменять способ, — пока заявка ждёт ответа
     * или подтверждена, но товар ещё не пришёл и накладной СДЭК нет:
     * например, пункт не принял посылку и удобнее курьер. Покупатель
     * получит письмо с новым способом.
     */
    public static function canDecide(OrderReturn $return): bool
    {
        return $return->status === OrderReturn::REQUESTED
            || ($return->status === OrderReturn::APPROVED && blank($return->shipment_uuid)
                && ! in_array($return->shipment_state, ['creating', 'unknown'], true));
    }

    public function reject(OrderReturn $record, string $reason, string $by): OrderReturn
    {
        $return = DB::transaction(function () use ($record, $reason, $by): OrderReturn {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
            if ($return->status !== OrderReturn::REQUESTED) {
                $this->fail('Отказать можно, пока заявка ждёт решения.', 'return');
            }
            $return->update(['status' => OrderReturn::REJECTED, 'reject_reason' => mb_substr(Text::utf8(trim($reason)), 0, 1000),
                'decided_at' => now(), 'decided_by' => mb_substr($by, 0, 120)]);

            return $return;
        });
        $this->mailer->returnUpdate($return, 'rejected');

        return $return->fresh();
    }

    /**
     * Обратная накладная СДЭК. Как с отправлениями: перед запросом —
     * «creating», ответ потерялся — «unknown», и следующее нажатие сначала
     * ищет возврат у СДЭК по номеру заявки, а не создаёт второй.
     */
    public function createWaybill(OrderReturn $record): OrderReturn
    {
        $previous = null;
        $return = DB::transaction(function () use ($record, &$previous): ?OrderReturn {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
            $previous = $return->shipment_state;
            if ($return->status !== OrderReturn::APPROVED || $return->method !== OrderReturn::METHOD_CDEK) {
                $this->fail('Накладная нужна подтверждённому возврату через СДЭК.', 'shipment');
            }
            if ($return->shipment_uuid) {
                return null;
            }
            if ($return->shipment_state === 'creating' && $return->updated_at?->gt(now()->subMinutes(2))) {
                $this->fail('Накладная уже создаётся — проверьте через минуту.', 'shipment');
            }
            $return->update(['shipment_state' => 'creating', 'shipment_error' => null]);

            return $return;
        });
        if ($return === null) {
            return $record->fresh();
        }

        $carrier = $this->cdek();
        try {
            // Прошлый ответ потерялся — сначала ищем возврат у СДЭК по номеру заявки.
            $found = in_array($previous, ['unknown', 'creating'], true) ? $carrier->findClientReturn($return->number) : null;
            $result = $found ?? $carrier->createClientReturn(
                $return->number,
                (string) $return->cdek_point,
                $this->buyer($return->order),
                $this->us(),
                $this->parcel($return),
                $this->waybillItems($return),
                'Возврат покупателя по заказу '.$return->order->number.' (livsi.shop)',
            );
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();
            $return->update(['shipment_state' => 'failed', 'shipment_error' => mb_substr($message, 0, 255)]);
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('СДЭК не ответил на создание возврата.', ['return' => $return->number, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            $return->update(['shipment_state' => 'unknown', 'shipment_error' => 'Ответ СДЭК не получен. Нажмите «Создать накладную» ещё раз — сайт сначала поищет её у СДЭК.']);
            $this->fail('СДЭК не ответил. Нажмите «Создать накладную» ещё раз через минуту — второй накладной не будет.', 'shipment');
        }

        $this->applyWaybill($return, $result);
        $return = $return->fresh();
        if ($return->shipment_number) {
            $this->mailer->returnUpdate($return, 'approved');
        }

        return $return;
    }

    /** Узнать у СДЭК, где посылка возврата. Пришла к нам — сказать в Telegram один раз. */
    public function refreshWaybill(OrderReturn $return): OrderReturn
    {
        if (! $return->shipment_uuid) {
            return $return;
        }
        $hadNumber = filled($return->shipment_number);
        $this->applyWaybill($return, $this->cdek()->clientReturnInfo($return->shipment_uuid));
        $return = $return->fresh();
        if (! $hadNumber && $return->shipment_number && $return->status === OrderReturn::APPROVED) {
            $this->mailer->returnUpdate($return, 'approved');
        }
        if ($return->arrived() && ! $return->arrived_notified_at && $return->status === OrderReturn::APPROVED) {
            $return->update(['arrived_notified_at' => now()]);
            app(BusinessNotifications::class)->queue('return_arrived', $return->id, 'issues');
        }

        return $return;
    }

    /** @param array<string, mixed> $result */
    private function applyWaybill(OrderReturn $return, array $result): void
    {
        $cost = $result['cost'] ?? null;
        $return->update(array_filter([
            'shipment_state' => 'created',
            'shipment_uuid' => $result['external_id'] ?? $return->shipment_uuid,
            'shipment_number' => $result['tracking'] ?? $return->shipment_number,
            'shipment_status_code' => $result['status_code'] ?? $return->shipment_status_code,
            'shipment_status_name' => $result['status_name'] ?? $return->shipment_status_name,
            // Стоимость СДЭК — то, что удерживаем, если доставку оплачивает покупатель.
            'shipping_cost' => is_int($cost) && $cost > 0 ? $cost : null,
            'shipment_checked_at' => now(),
        ], fn ($value) => $value !== null) + ['shipment_error' => null]);
    }

    /**
     * Товар пришёл. В продажу — если цел и в заводской упаковке.
     * Для полного возврата это и есть «принят на склад» заказа.
     */
    public function receive(OrderReturn $record, bool $restock, ?string $note, string $by): OrderReturn
    {
        return DB::transaction(function () use ($record, $restock, $note, $by): OrderReturn {
            $return = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
            if ($return->status !== OrderReturn::APPROVED) {
                $this->fail('Отметить получение можно у подтверждённого возврата.', 'return');
            }
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            if ($restock) {
                foreach ($return->items as $row) {
                    $item = $order->items()->find((int) $row['item_id']);
                    $quota = $item ? StockQuota::query()->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first() : null;
                    if (! $quota || $quota->sold < 1) {
                        continue;
                    }
                    $back = min((int) $row['qty'], (int) $quota->sold);
                    $quota->decrement('sold', $back);
                    StockQuotaMovement::create(['stock_quota_id' => $quota->id, 'type' => 'adjust', 'qty' => $back,
                        'comment' => 'Возврат '.$return->number.' принят — товар снова в продаже']);
                }
                if ($return->isFull() && ! $order->returned_to_stock_at) {
                    $order->forceFill(['returned_to_stock_at' => now(), 'returned_to_stock_by' => mb_substr($by, 0, 120)])->save();
                }
            }
            $return->update(['status' => OrderReturn::RECEIVED, 'received_at' => now(), 'received_by' => mb_substr($by, 0, 120),
                'restocked' => $restock, 'receive_note' => filled($note) ? mb_substr(Text::utf8(trim($note)), 0, 1000) : null]);
            OrderFulfillmentEvent::create([
                'order_id' => $order->id, 'user_id' => auth()->id(),
                'status' => Fulfillment::current($order) ?? Fulfillment::DELIVERED,
                'source' => OrderFulfillmentEvent::SOURCE_MANUAL,
                'comment' => mb_substr('Возврат '.$return->number.': товар получен'.($restock ? ', снова в продаже' : ', в продажу не возвращён')
                    .(filled($note) ? ' — '.$note : '').' · '.$by, 0, 500),
            ]);

            return $return->fresh();
        });
    }

    /**
     * Вернуть деньги. Сумму можно поправить: например, если СДЭК выставил
     * за доставку не столько, сколько показал при создании.
     */
    public function refund(OrderReturn $record, int $amount, string $by): OrderReturn
    {
        $return = $record->fresh();
        if ($return->status !== OrderReturn::RECEIVED) {
            $this->fail('Сначала отметьте, что товар получен и проверен.', 'return');
        }
        try {
            $refund = app(RefundService::class)->refundReturn($return, $amount, $by);
        } catch (ValidationException $exception) {
            $return->update(['refund_error' => mb_substr($exception->validator->errors()->first(), 0, 255)]);
            throw $exception;
        }
        if ($refund->status !== OrderReceipt::SUCCEEDED) {
            $return->update(['refund_error' => 'Возврат оформлен — банк обрабатывает.']);
        }

        return $return->fresh();
    }

    /** Покупатель — отправитель возврата. @return array{name: string, phone: string} */
    private function buyer(Order $order): array
    {
        $phone = Phone::format($order->customer_phone);
        if (! $phone) {
            $this->fail('В заказе нет корректного телефона покупателя — СДЭК без него возврат не примет.', 'shipment');
        }

        return ['name' => (string) $order->customer_name, 'phone' => $phone];
    }

    /** Мы — получатель возврата. @return array{name: string, phone: string} */
    private function us(): array
    {
        $sender = app(ShipmentService::class)->senderDefaults();
        $phone = Phone::format((string) ($sender['phone'] ?? ''));
        if (blank($sender['name'] ?? null) || ! $phone) {
            $this->fail('Не заполнены название и телефон магазина (реквизиты продавца или телефон отправителя в настройках) — они нужны СДЭК как получатель возврата.', 'shipment');
        }

        return ['name' => (string) $sender['name'], 'phone' => $phone];
    }

    /** Вес и размеры: у полного возврата — как у посылки заказа, у частичного — по весу позиций. */
    private function parcel(OrderReturn $return): array
    {
        $order = $return->order()->with('items.variant')->first();
        $parcel = $order->delivery_quote['parcel'] ?? null;
        $box = is_array($parcel) && is_int($parcel['length'] ?? null)
            ? ['length' => max(1, $parcel['length']), 'width' => max(1, (int) $parcel['width']), 'height' => max(1, (int) $parcel['height'])]
            : ['length' => 20, 'width' => 15, 'height' => 10];
        $weight = 0;
        foreach ($return->items as $row) {
            $item = $order->items->firstWhere('id', (int) $row['item_id']);
            $weight += max(50, (int) ($item?->variant?->weight_g ?? 300)) * (int) $row['qty'];
        }
        $weight = $return->isFull() && is_int($parcel['weight'] ?? null) ? max($parcel['weight'], $weight) : $weight + 100;

        return ['weight' => max(100, $weight)] + $box;
    }

    /** @return list<array{name: string, sku: string, cost: int, weight: int, amount: int}> */
    private function waybillItems(OrderReturn $return): array
    {
        $order = $return->order()->with('items.variant')->first();

        return array_map(function (array $row) use ($order): array {
            $item = $order->items->firstWhere('id', (int) $row['item_id']);
            $qty = max(1, (int) $row['qty']);

            return ['name' => (string) $row['label'], 'sku' => (string) $row['sku'], 'cost' => intdiv((int) $row['amount'], $qty),
                'weight' => max(1, (int) ($item?->variant?->weight_g ?? 300)), 'amount' => $qty];
        }, $return->items);
    }

    private function cdek(): CdekCarrier
    {
        $carrier = $this->delivery->carrier('cdek');
        if (! $carrier instanceof CdekCarrier || ! $carrier->isConfigured()) {
            $this->fail('СДЭК не подключён в «Интеграциях доставки».', 'shipment');
        }

        return $carrier;
    }

    /** Ссылка на фото для админки. */
    public static function photoExists(OrderReturn $return, int $index): bool
    {
        $path = $return->photos[$index] ?? null;

        return is_string($path) && Storage::disk(self::PHOTO_DISK)->exists($path);
    }

    /** Сумма по расчёту — словами для админки. */
    public static function breakdown(OrderReturn $return): string
    {
        $parts = ['товар '.Money::rub((int) $return->goods_amount)];
        if ($return->deliveryRefund() > 0) {
            $parts[] = '+ доставка заказа '.Money::rub($return->deliveryRefund());
        }
        if ($return->deduction() > 0) {
            $parts[] = '− обратная доставка '.Money::rub($return->deduction());
        }

        return implode(' ', $parts).' = '.Money::rub($return->expectedRefund());
    }

    private function fail(string $message, string $key = 'return'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
