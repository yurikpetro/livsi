<?php

namespace App\Monitoring;

use App\Jobs\SendPaymentTelegram;
use App\Models\Payment;
use Throwable;

class PaymentNotifications
{
    public function queue(Payment $payment): void
    {
        try {
            if (! app(Telegram::class)->isConfigured()) {
                return;
            }

            // Отдельная database-очередь по умолчанию: даже при QUEUE_CONNECTION=sync
            // покупатель не ждёт сетевого запроса в Telegram.
            SendPaymentTelegram::dispatch($payment->id)
                ->onConnection(config('livsi.telegram.payments_connection', 'database'));
        } catch (Throwable $e) {
            TelegramFailure::record($e, ['topic' => 'payments', 'payment_id' => $payment->id, 'stage' => 'dispatch']);
        }
    }
}
