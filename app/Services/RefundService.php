<?php

namespace App\Services;

use App\Delivery\DirectShipmentService;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderReceipt;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Payments\ReceiptBuilder;
use App\Payments\YooKassaGateway;
use App\Support\Fulfillment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * «Отменить заказ и вернуть деньги» — только из админки.
 *
 * 1. Пока посылка не передана, заявка у службы снимается. Не снялась —
 *    деньги не трогаем: иначе служба повезёт неоплаченный заказ.
 * 2. Возврат всей суммы через ЮKassa. Если второй чек уже был (покупатель
 *    получал заказ), к возврату прикладывается чек с теми же КИЗ — коды
 *    вернутся в оборот. Если не было — ЮKassa сама пробьёт возврат предоплаты.
 * 3. Когда ЮKassa подтвердила: заказ «Возвращён», не отгруженный товар —
 *    обратно в квоту сайта, КИЗ освобождаются (их можно сканировать в другой
 *    заказ; список остаётся в записи возврата).
 */
class RefundService
{
    public function __construct(
        private readonly YooKassaGateway $kassa,
        private readonly DirectShipmentService $shipments,
        private readonly ReceiptService $receipts,
    ) {}

    /** Что сказать администратору перед возвратом. */
    public function warning(Order $order): ?string
    {
        return match (Fulfillment::current($order)) {
            Fulfillment::SHIPPED, Fulfillment::AT_PICKUP => 'Посылка уже у службы — заявку отменить нельзя. После возврата денег дождитесь, пока посылка вернётся.',
            Fulfillment::DELIVERED => 'Покупатель уже получил заказ. Возвращайте деньги, когда товар вернулся к вам.',
            Fulfillment::RETURNED => 'Покупатель не забрал заказ, посылка возвращается. Обычно возвращают сумму без стоимости доставки.',
            default => match (true) {
                filled($order->shipment_external_id) => 'Заявка у службы будет отменена.',
                filled($order->apiship_order_id) => 'Заказ оформлен через ApiShip — заявку там отмените вручную.',
                default => null,
            },
        };
    }

    public function canRefund(Order $order): bool
    {
        $refund = $order->receipt(OrderReceipt::KIND_REFUND);

        return $order->status === Order::STATUS_PAID && $this->receipts->payment($order) !== null
            && ! ($refund && in_array($refund->status, [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED], true));
    }

    /** Сколько удержать за доставку по выбору «без стоимости доставки». */
    public function deliveryToKeep(Order $order): int
    {
        return max(0, (int) $order->delivery_price);
    }

    public function refund(Order $record, string $reason, string $by, bool $keepDelivery = false): OrderReceipt
    {
        $order = $record->fresh();
        if ($order->status !== Order::STATUS_PAID) {
            $this->fail('Вернуть деньги можно только по оплаченному заказу.');
        }
        $previous = $order->receipt(OrderReceipt::KIND_REFUND);
        if ($previous && in_array($previous->status, [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED], true)) {
            $this->fail('Возврат по заказу уже оформлен.');
        }
        $payment = $this->receipts->payment($order);
        if (! $payment) {
            $this->fail('По заказу нет оплаты через ЮKassa — вернуть деньги отсюда нельзя.');
        }
        $closing = $order->receipt(OrderReceipt::KIND_CLOSING);
        if ($closing && in_array($closing->status, [OrderReceipt::NEW, OrderReceipt::PENDING], true) && $closing->sent_at) {
            $this->fail('Второй чек ещё обрабатывается в ЮKassa — повторите через несколько минут.');
        }

        // Пока посылка не передана — снять заявку у службы. Не вышло — стоп.
        if ($order->shipment_external_id && in_array(Fulfillment::current($order), [null, Fulfillment::AWAITING, Fulfillment::ASSEMBLED], true)) {
            $this->shipments->cancel($order, 'Отмена заказа · '.$by, OrderFulfillmentEvent::SOURCE_MANUAL);
            $order = $order->fresh();
        }

        // Отправление ещё стоит в очереди на автоматическое создание — снимаем,
        // чтобы служба не получила заявку по заказу, за который вернули деньги.
        // Если заявка уходит прямо сейчас — ждём её, иначе останется висеть.
        $unqueued = false;
        $previousState = $order->shipment_state;
        if (! $order->hasShipment()) {
            if (in_array($order->shipment_state, ['creating', 'unknown'], true)) {
                $this->fail('Отправление прямо сейчас создаётся у службы — повторите возврат через минуту.');
            }
            if (in_array($order->shipment_state, [null, 'queued'], true)) {
                $unqueued = true;
                $order->forceFill(['shipment_state' => 'cancelled', 'shipment_status_name' => 'Отменено: возврат денег'])->save();
            }
        }

        $order->load('items.marks');
        $builder = new ReceiptBuilder($order);
        $keep = $keepDelivery ? $this->deliveryToKeep($order) : 0;
        if ($keepDelivery && $keep < 1) {
            $this->fail('Покупатель не платил за доставку — удерживать нечего. Верните всю сумму.');
        }
        $amount = (int) $payment->amount - $keep;
        // Чек возврата. Полный возврат до второго чека ЮKassa пробьёт сама. После
        // второго чека — свой, с КИЗ; при удержании доставки — свой без строки доставки.
        $items = match (true) {
            $closing?->status === OrderReceipt::SUCCEEDED => $builder->closingItems(),
            $keep > 0 => $builder->build()['items'],
            default => null,
        };
        if ($items !== null && $keep > 0) {
            $items = array_values(array_filter($items, fn (array $item): bool => ($item['payment_subject'] ?? '') !== 'service'));
        }
        if ($items !== null && ReceiptBuilder::sum($items) !== $amount) {
            $this->fail('Сумма чека возврата не сходится с суммой возврата. Верните всю сумму или пробейте чек вручную.');
        }
        $receipt = $items !== null ? ['customer' => $builder->customer(), 'items' => $items] : null;
        // Прошлая попытка оборвалась на связи (итог неизвестен) — повторяем с тем же
        // ключом: если ЮKassa тот возврат уже провела, она вернёт его, а не сделает второй.
        $attempt = (int) ($previous?->payload['attempt'] ?? 0) + ($previous?->status === OrderReceipt::NEW ? 0 : 1);
        $refund = OrderReceipt::query()->updateOrCreate(
            ['order_id' => $order->id, 'kind' => OrderReceipt::KIND_REFUND],
            ['status' => OrderReceipt::NEW, 'external_id' => null, 'error' => null, 'response' => null, 'done_at' => null,
                'idempotence_key' => ReceiptService::key('refund/'.$order->id.'/'.$attempt), 'amount' => $amount, 'created_by' => mb_substr($by, 0, 120),
                'payload' => ['reason' => mb_substr($reason, 0, 500), 'attempt' => $attempt, 'with_receipt' => $receipt !== null, 'kept_delivery' => $keep]],
        );

        try {
            $answer = $this->kassa->createRefund((string) $payment->external_id, $amount, $receipt, 'Возврат по заказу '.$order->number, $refund->idempotence_key);
        } catch (ConnectionException $exception) {
            $refund->update(['error' => 'Нет связи с ЮKassa — итог неизвестен. Нажмите возврат ещё раз: повтор безопасен.']);
            $this->fail('Нет связи с ЮKassa. Нажмите «Вернуть деньги» ещё раз через минуту — второго возврата не будет, ЮKassa узнает повтор.');
        } catch (RuntimeException $exception) {
            if ($unqueued) {
                // Деньги не вернулись — заказ снова ждёт отправления, как до нажатия.
                $order->forceFill(['shipment_state' => $previousState, 'shipment_status_name' => null])->save();
            }
            $refund->update(['status' => OrderReceipt::CANCELED, 'error' => mb_substr($exception->getMessage(), 0, 500)]);
            $this->fail('ЮKassa не приняла возврат: '.mb_substr($exception->getMessage(), 0, 200));
        }

        OrderFulfillmentEvent::create([
            'order_id' => $order->id, 'user_id' => auth()->id(),
            'status' => Fulfillment::current($order) ?? Fulfillment::AWAITING,
            'source' => OrderFulfillmentEvent::SOURCE_MANUAL,
            'comment' => mb_substr('Отмена заказа, возврат '.number_format($amount / 100, 2, ',', ' ').' ₽'
                .($keep ? ' (без доставки '.number_format($keep / 100, 2, ',', ' ').' ₽)' : '').': '.$reason.' · '.$by, 0, 500),
        ]);

        return $this->apply($refund, $answer);
    }

    /** Узнать у ЮKassa, чем закончился возврат в обработке. */
    public function refresh(OrderReceipt $refund): OrderReceipt
    {
        if ($refund->kind !== OrderReceipt::KIND_REFUND || ! $refund->external_id || $refund->status !== OrderReceipt::PENDING) {
            return $refund;
        }

        return $this->apply($refund, $this->kassa->refund($refund->external_id));
    }

    /** @param array<string, mixed> $answer */
    private function apply(OrderReceipt $refund, array $answer): OrderReceipt
    {
        $status = (string) ($answer['status'] ?? OrderReceipt::PENDING);
        $refund->update([
            'external_id' => $answer['id'] ?? $refund->external_id,
            'status' => $status,
            'response' => $answer,
            'sent_at' => $refund->sent_at ?? now(),
            'error' => $status === OrderReceipt::CANCELED
                ? mb_substr('ЮKassa отклонила возврат'.(isset($answer['cancellation_details']['reason']) ? ': '.$answer['cancellation_details']['reason'] : ''), 0, 500)
                : null,
        ]);
        if ($status === OrderReceipt::SUCCEEDED) {
            $this->finish($refund);
        } elseif ($status === OrderReceipt::CANCELED) {
            Log::error('Возврат денег отклонён ЮKassa.', ['order' => $refund->order?->number, 'refund' => $refund->external_id]);
        }

        return $refund->fresh();
    }

    /** Деньги вернулись: заказ «Возвращён», товар — в квоту, КИЗ свободны. */
    private function finish(OrderReceipt $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            if ($order->status === Order::STATUS_REFUNDED) {
                return;
            }
            $order->load('items.marks');

            // Товар со склада не уезжал — возвращаем его в продажу на сайте.
            $stayed = in_array(Fulfillment::current($order), [null, Fulfillment::AWAITING, Fulfillment::ASSEMBLED], true);
            if ($stayed) {
                foreach ($order->items as $item) {
                    $quota = StockQuota::query()->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
                    if (! $quota || $quota->sold < 1) {
                        continue;
                    }
                    $back = min((int) $item->quantity, (int) $quota->sold);
                    $quota->decrement('sold', $back);
                    StockQuotaMovement::create(['stock_quota_id' => $quota->id, 'type' => 'adjust', 'qty' => $back,
                        'comment' => 'Отменён заказ '.$order->number.' — товар вернулся в продажу']);
                }
            }

            $released = $order->items->flatMap(fn ($item) => $item->marks->map(fn ($mark): string => $mark->gtin.' '.$mark->serial))->values()->all();
            $order->marks()->delete();
            $refund->update(['done_at' => now(), 'payload' => array_merge($refund->payload ?? [], ['released_marks' => $released])]);
            // Не уехавший заказ больше не «собран» и не «ждёт сборки»: он отменён.
            // Его нет ни в сборке, ни в доставке; история — в журнале заказа.
            $order->forceFill(array_merge(['status' => Order::STATUS_REFUNDED, 'cancelled_at' => $order->cancelled_at ?? now()],
                $stayed ? ['fulfillment_status' => null, 'fulfillment_status_at' => now()] : []))->save();
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['refund' => $message]);
    }
}
