<?php

namespace App\Services;

use App\Delivery\DirectShipmentService;
use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\OrderReceipt;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Payments\ReceiptBuilder;
use App\Payments\TochkaClient;
use App\Payments\YooKassaGateway;
use App\Support\Fulfillment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * «Отменить заказ и вернуть деньги» — только из админки.
 *
 * 1. Пока посылка не передана, заявка у службы снимается. Не снялась —
 *    деньги не трогаем: иначе служба повезёт неоплаченный заказ.
 * 2. Возврат всей суммы через того, кто принимал оплату.
 *    ЮKassa: если второй чек уже был (покупатель получал заказ), к возврату
 *    прикладывается чек с теми же КИЗ — коды вернутся в оборот. Если не было —
 *    ЮKassa сама пробьёт возврат предоплаты.
 *    Точка: деньги возвращает банк; чек возврата пробивает сайт через
 *    CloudKassir, если это включено в «Интеграциях оплаты» (иначе — связка
 *    Точка — CloudKassir или владелец вручную).
 * 3. Когда возврат подтверждён: заказ «Возвращён», не отгруженный товар —
 *    обратно в квоту сайта, КИЗ освобождаются (их можно сканировать в другой
 *    заказ; список остаётся в записи возврата).
 */
class RefundService
{
    public function __construct(
        private readonly YooKassaGateway $kassa,
        private readonly DirectShipmentService $shipments,
        private readonly ReceiptService $receipts,
        private readonly TochkaClient $tochka,
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
            $this->fail('По заказу нет оплаты через сайт — вернуть деньги отсюда нельзя.');
        }
        $closing = $order->receipt(OrderReceipt::KIND_CLOSING);
        if ($closing && in_array($closing->status, [OrderReceipt::NEW, OrderReceipt::PENDING], true) && $closing->sent_at) {
            $this->fail('Второй чек ещё обрабатывается в кассе — повторите через несколько минут.');
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
        $tochka = $payment->gateway === PaymentSettings::TOCHKA;
        // Чек возврата. Полный возврат до второго чека ЮKassa пробьёт сама. После
        // второго чека — свой, с КИЗ; при удержании доставки — свой без строки доставки.
        // По оплате Точки чек нужен, только если его пробивает сайт.
        $siteReceipt = $tochka && PaymentSettings::siteRefundReceipts();
        $items = match (true) {
            $tochka && ! $siteReceipt => null,
            $closing?->status === OrderReceipt::SUCCEEDED => $builder->closingItems(),
            $keep > 0 || $siteReceipt => $builder->build()['items'],
            default => null,
        };
        if ($items !== null && $keep > 0) {
            $items = array_values(array_filter($items, fn (array $item): bool => ($item['payment_subject'] ?? '') !== 'service'));
        }
        if ($items !== null && ReceiptBuilder::sum($items) !== $amount) {
            $this->fail('Сумма чека возврата не сходится с суммой возврата. Верните всю сумму или пробейте чек вручную.');
        }
        if ($tochka) {
            return $this->refundTochka($order, $payment, $previous, $amount, $keep, $items, $reason, $by, $unqueued, $previousState);
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

    /**
     * Возврат по оплате Точки.
     *
     * Ключа повтора у Точки нет, поэтому перед возвратом спрашиваем у банка,
     * не было ли уже возврата по этому платежу (прошлая попытка оборвалась на
     * связи или деньги вернули в кабинете банка). Был — второй не делаем.
     *
     * @param  list<array<string, mixed>>|null  $items  строки чека возврата, если его пробивает сайт
     */
    private function refundTochka(Order $order, Payment $payment, ?OrderReceipt $previous, int $amount, int $keep, ?array $items,
        string $reason, string $by, bool $unqueued, ?string $previousState): OrderReceipt
    {
        $restore = function () use ($order, $unqueued, $previousState): void {
            if ($unqueued) {
                // Деньги не вернулись — заказ снова ждёт отправления, как до нажатия.
                $order->forceFill(['shipment_state' => $previousState, 'shipment_status_name' => null])->save();
            }
        };
        $attempt = (int) ($previous?->payload['attempt'] ?? 0) + ($previous?->status === OrderReceipt::NEW ? 0 : 1);
        $refund = OrderReceipt::query()->updateOrCreate(
            ['order_id' => $order->id, 'kind' => OrderReceipt::KIND_REFUND],
            ['status' => OrderReceipt::NEW, 'external_id' => null, 'error' => null, 'response' => null, 'done_at' => null,
                'idempotence_key' => ReceiptService::key('refund/'.$order->id.'/'.$attempt), 'amount' => $amount, 'created_by' => mb_substr($by, 0, 120),
                'payload' => ['reason' => mb_substr($reason, 0, 500), 'attempt' => $attempt, 'with_receipt' => $items !== null,
                    'kept_delivery' => $keep, 'provider' => PaymentSettings::TOCHKA]],
        );

        try {
            $operation = $this->tochka->payment((string) $payment->external_id);
        } catch (Throwable $exception) {
            $restore();
            $refund->update(['error' => mb_substr('Не удалось проверить платёж у Точки: '.$exception->getMessage(), 0, 500)]);
            $this->fail('Точка не ответила на проверку платежа — деньги не возвращены. Повторите через минуту.');
        }
        $done = array_values(array_filter((array) ($operation['Order'] ?? []), fn ($row): bool => is_array($row) && ($row['type'] ?? '') === 'refund'));
        $status = strtoupper((string) ($operation['status'] ?? ''));

        if ($done !== [] || in_array($status, ['ON-REFUND', 'REFUNDED', 'REFUNDED_PARTIALLY'], true)) {
            // Возврат уже есть у банка — второй не делаем, фиксируем тот, что был.
            $returned = (int) round(array_sum(array_map(fn (array $row): float => (float) ($row['amount'] ?? 0), $done)) * 100);
            $answer = ['isRefund' => true, 'orderId' => $done[0]['orderId'] ?? null, 'amount' => $returned ?: $amount / 100, 'found' => true, 'status' => $status];
            $amount = $returned ?: $amount;
        } elseif ($status !== 'APPROVED') {
            $restore();
            $refund->update(['status' => OrderReceipt::CANCELED, 'error' => 'Платёж у Точки в статусе '.$status.' — возврат невозможен.']);
            $this->fail('Платёж у Точки в статусе '.$status.' — вернуть деньги по нему нельзя.');
        } else {
            try {
                $answer = $this->tochka->refund((string) $payment->external_id, $amount);
            } catch (ConnectionException $exception) {
                $refund->update(['error' => 'Нет связи с Точкой — итог неизвестен. Нажмите возврат ещё раз: сайт сначала спросит банк, второго возврата не будет.']);
                $this->fail('Нет связи с Точкой. Нажмите «Вернуть деньги» ещё раз через минуту — сайт сначала спросит у банка, прошёл ли возврат, второго не будет.');
            } catch (RuntimeException $exception) {
                $restore();
                $refund->update(['status' => OrderReceipt::CANCELED, 'error' => mb_substr($exception->getMessage(), 0, 500)]);
                $this->fail('Точка не приняла возврат: '.mb_substr($exception->getMessage(), 0, 200));
            }
            if (($answer['isRefund'] ?? false) !== true) {
                $restore();
                $refund->update(['status' => OrderReceipt::CANCELED, 'response' => $answer, 'error' => 'Точка ответила, что возврат не проведён.']);
                $this->fail('Точка ответила, что возврат не проведён. Проверьте платёж в интернет-банке.');
            }
        }

        OrderFulfillmentEvent::create([
            'order_id' => $order->id, 'user_id' => auth()->id(),
            'status' => Fulfillment::current($order) ?? Fulfillment::AWAITING,
            'source' => OrderFulfillmentEvent::SOURCE_MANUAL,
            'comment' => mb_substr('Отмена заказа, возврат '.number_format($amount / 100, 2, ',', ' ').' ₽ через Точку'
                .($keep ? ' (без доставки '.number_format($keep / 100, 2, ',', ' ').' ₽)' : '').': '.$reason.' · '.$by, 0, 500),
        ]);
        $refund->update([
            'status' => OrderReceipt::SUCCEEDED, 'external_id' => $answer['orderId'] ?? null, 'response' => $answer,
            'amount' => $amount, 'sent_at' => now(), 'error' => null,
        ]);
        $this->finish($refund);

        // Чек возврата — после денег: деньги уже у покупателя, а чек при отказе
        // кассы повторяется отдельно (его видно в заказе). Уже пробитый
        // (прошлая попытка) второй раз не уйдёт.
        if ($items !== null) {
            try {
                $this->receipts->sendRefundReceipt($order, $items, $amount);
            } catch (Throwable $exception) {
                Log::error('Чек возврата не отправлен.', ['order' => $order->number, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            }
        }

        return $refund->fresh();
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
