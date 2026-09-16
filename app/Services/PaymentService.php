<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
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
    public function sync(Payment $payment): Order
    {
        return match ($payment->status) {
            Payment::STATUS_SUCCEEDED => $this->markPaid($payment),
            Payment::STATUS_CANCELED  => $this->markCancelled($payment),
            default                   => $payment->order,
        };
    }

    public function markPaid(Payment $payment): Order
    {
        $order = $payment->order;

        if ($order->isPaid()) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            // Резерв превращается в продажу: сначала снимаем резерв,
            // затем увеличиваем продано — иначе доступное количество
            // на мгновение окажется завышенным.
            foreach ($order->items as $item) {
                $quota = StockQuota::where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if (! $quota) {
                    continue;
                }

                $quota->decrement('reserved', min($item->quantity, $quota->reserved));
                $quota->increment('sold', $item->quantity);

                StockQuotaMovement::create([
                    'stock_quota_id' => $quota->id,
                    'type'           => 'sell',
                    'qty'            => $item->quantity,
                    'comment'        => 'Оплачен заказ ' . $order->number,
                ]);
            }

            $order->update([
                'status'  => Order::STATUS_PAID,
                'paid_at' => now(),
            ]);

            Log::info('Заказ оплачен.', ['order' => $order->number]);

            return $order->fresh();
        });
    }

    public function markCancelled(Payment $payment): Order
    {
        $order = $payment->order;

        // Оплаченный заказ отменять по вебхуку нельзя: сначала приходит
        // успех, потом может прийти отмена по другой попытке оплаты.
        if ($order->isPaid() || $order->status === Order::STATUS_CANCELLED) {
            return $order;
        }

        // Пока есть другая живая попытка оплаты, заказ остаётся в ожидании:
        // человек мог закрыть первую страницу и оплатить со второй.
        $hasPending = $order->payments()
            ->whereNotIn('status', [Payment::STATUS_CANCELED])
            ->where('id', '!=', $payment->id)
            ->exists();

        if ($hasPending) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            $this->releaseReserve($order);

            $order->update([
                'status'       => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            return $order->fresh();
        });
    }

    /** Вернуть зарезервированное в доступное — товар снова можно купить. */
    public function releaseReserve(Order $order): void
    {
        foreach ($order->items as $item) {
            $quota = StockQuota::where('product_variant_id', $item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if (! $quota || $quota->reserved < 1) {
                continue;
            }

            $release = min($item->quantity, $quota->reserved);
            $quota->decrement('reserved', $release);

            StockQuotaMovement::create([
                'stock_quota_id' => $quota->id,
                'type'           => 'release',
                'qty'            => -$release,
                'comment'        => 'Снят резерв по заказу ' . $order->number,
            ]);
        }
    }
}
