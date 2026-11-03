<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Monitoring\PaymentNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Что происходит с заказом и складом, когда меняется статус платежа.
 *
 * Вынесено из шлюза: правило «оплачено — значит списываем квоту» одно
 * и то же независимо от провайдера, и вызывается оно из трёх мест —
 * вебхука, опроса статуса и ручного действия в админке.
 *
 * Все переходы идемпотентны: провайдер доставляет уведомления «хотя бы
 * один раз», то есть один и тот же вебхук придёт повторно, и второй раз
 * списывать со склада нельзя.
 */
class PaymentService
{
    public function __construct(
        private readonly OrderMailer $mailer,
        private readonly PaymentNotifications $notifications,
        private readonly ShipmentAutomation $shipments,
    ) {}

    public function sync(Payment $payment): Order
    {
        return match ($payment->status) {
            Payment::STATUS_SUCCEEDED => $this->markPaid($payment),
            Payment::STATUS_CANCELED => $this->markCancelled($payment),
            default => $payment->order,
        };
    }

    public function markPaid(Payment $payment): Order
    {
        [$order, $changed] = DB::transaction(function () use ($payment) {
            // Вебхук и возврат из платёжной формы могут прийти одновременно.
            // Проверяем свежую запись под блокировкой, а не кеш связи модели.
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            // За возвращённый заказ платёж в ЮKassa так и остаётся «succeeded»:
            // повторное уведомление не должно снова сделать заказ оплаченным,
            // списать товар и поставить отправление в очередь.
            if ($order->isPaid() || $order->status === Order::STATUS_REFUNDED) {
                return [$order, false];
            }
            // Переводим резерв в продажу одним обновлением: промежуточное
            // увеличение «доступно» создало бы ложное повторное предупреждение.
            foreach ($order->items as $item) {
                $quota = StockQuota::where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if (! $quota) {
                    continue;
                }

                $quota->update([
                    'reserved' => $quota->reserved - min($item->quantity, $quota->reserved),
                    'sold' => $quota->sold + $item->quantity,
                ]);

                StockQuotaMovement::create([
                    'stock_quota_id' => $quota->id,
                    'type' => 'sell',
                    'qty' => $item->quantity,
                    'comment' => 'Оплачен заказ '.$order->number,
                ]);
            }

            $order->update([
                'status' => Order::STATUS_PAID,
                'paid_at' => now(),
                'shipment_automatic' => true,
                'shipment_state' => $order->hasShipment() ? $order->shipment_state : ($order->shipment_state ?: 'queued'),
            ]);

            Log::info('Заказ оплачен.', ['order' => $order->number]);

            return [$order->fresh(), true];
        });

        // Письма за пределами транзакции: очередь у нас в базе, и задание,
        // поставленное внутри, исчезло бы вместе с откатом — а человек
        // остался бы с оплаченным заказом и без письма.
        //
        // Место выбрано не случайно: сюда попадаем только на самом переходе
        // в «оплачен». Вебхук провайдера приходит повторно, и письмо отсюда
        // уходит ровно один раз.
        if ($changed) {
            DB::afterCommit(function () use ($order, $payment) {
                $this->notifications->queue($payment);
                $this->mailer->paid($order);
                $this->shipments->queue($order);
            });
        }

        return $order;
    }

    public function markCancelled(Payment $payment): Order
    {
        return $this->cancelIfNoLivePayment($payment->order_id);
    }

    /** Снимает резерв ровно один раз и только когда все попытки оплаты завершены. */
    public function cancelIfNoLivePayment(int $orderId, bool $notify = true): Order
    {
        [$order, $changed] = DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== Order::STATUS_AWAITING_PAYMENT
                || $order->payments()->whereNotIn('status', [Payment::STATUS_CANCELED])->exists()) {
                return [$order, false];
            }

            $this->releaseReserve($order);
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            return [$order->fresh(), true];
        });

        if ($changed && $notify) {
            DB::afterCommit(fn () => $this->mailer->cancelled($order));
        }

        return $order;
    }

    /** Вернуть зарезервированное в доступное — товар снова можно купить. */
    public function releaseReserve(Order $order): void
    {
        foreach ($order->items->groupBy('product_variant_id') as $variantId => $items) {
            $quota = StockQuota::where('product_variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if (! $quota || $quota->reserved < 1) {
                continue;
            }

            $alreadyReleased = -(int) StockQuotaMovement::query()
                ->where('stock_quota_id', $quota->id)
                ->where('type', 'release')
                ->where('comment', 'Снят резерв по заказу '.$order->number)
                ->sum('qty');
            $remaining = $items->sum('quantity') - $alreadyReleased;
            $release = min(max(0, $remaining), $quota->reserved);

            if ($release < 1) {
                continue;
            }

            $quota->decrement('reserved', $release);

            StockQuotaMovement::create([
                'stock_quota_id' => $quota->id,
                'type' => 'release',
                'qty' => -$release,
                'comment' => 'Снят резерв по заказу '.$order->number,
            ]);
        }
    }
}
