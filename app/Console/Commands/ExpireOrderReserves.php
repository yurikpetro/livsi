<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('orders:expire-reserves {--dry-run : Показать число просроченных заказов без изменений}')]
#[Description('Отменить просроченные неоплаченные заказы и освободить товар')]
class ExpireOrderReserves extends Command
{
    public function handle(PaymentGateway $gateway, PaymentService $payments): int
    {
        $minutes = max(1, (int) config('livsi.order_reserve_minutes', 1440));
        $cutoff = now()->subMinutes($minutes);
        $expired = 0;
        $deferred = 0;

        $query = Order::query()
            ->where('status', Order::STATUS_AWAITING_PAYMENT)
            ->where('created_at', '<=', $cutoff);

        if ($this->option('dry-run')) {
            $this->info('Просроченных заказов для сверки: '.$query->count());

            return self::SUCCESS;
        }

        $query->chunkById(50, function ($orders) use ($gateway, $payments, &$expired, &$deferred): void {
            foreach ($orders as $order) {
                try {
                    foreach ($order->payments()->orderBy('id')->get() as $payment) {
                        if ($payment->status === Payment::STATUS_CANCELED) {
                            continue;
                        }

                        if ($payment->status === Payment::STATUS_SUCCEEDED) {
                            $payments->markPaid($payment);

                            continue;
                        }

                        if (! $payment->external_id) {
                            throw new RuntimeException('У попытки оплаты нет ID провайдера; нужна ручная сверка.');
                        }

                        $payment = $gateway->refreshStatus($payment);

                        if ($payment->status === Payment::STATUS_WAITING_FOR_CAPTURE) {
                            $payment = $gateway->cancelPayment($payment);
                        }

                        if ($payment->isFinal()) {
                            $payments->sync($payment);
                        }
                    }

                    $result = $payments->cancelIfNoLivePayment($order->id);

                    if ($result->status === Order::STATUS_CANCELLED) {
                        $expired++;
                    } elseif ($result->status === Order::STATUS_AWAITING_PAYMENT) {
                        $deferred++;
                    }
                } catch (Throwable $e) {
                    $deferred++;
                    Log::warning('Резерв заказа не снят: статус оплаты не подтверждён.', [
                        'order' => $order->number,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Резерв снят: {$expired}; отложено: {$deferred}.");

        return self::SUCCESS;
    }
}
