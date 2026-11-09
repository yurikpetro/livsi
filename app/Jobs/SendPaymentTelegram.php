<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Monitoring\PaymentReport;
use App\Monitoring\Telegram;
use App\Monitoring\TelegramFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendPaymentTelegram implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $paymentId) {}

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(Telegram $telegram, PaymentReport $report): void
    {
        try {
            $payment = Payment::with('order')->find($this->paymentId);
            if (! $payment || $payment->status !== Payment::STATUS_SUCCEEDED || $payment->telegram_notified_at) {
                return;
            }

            if (! $telegram->topic('payments')) {
                throw new RuntimeException('Не задан TELEGRAM_TOPIC_PAYMENTS: отправка в общую ленту запрещена.');
            }

            $telegram->send($report->toHtml($payment), 'payments', SendBusinessTelegram::WAIT);
            $payment->update(['telegram_notified_at' => now()]);
        } catch (Throwable $e) {
            TelegramFailure::record($e, ['topic' => 'payments', 'payment_id' => $this->paymentId, 'attempt' => $this->attempts()]);

            // Запрос ушёл, ответ не дождались — сообщение, скорее всего, в группе: второго не шлём.
            if (Telegram::maybeDelivered($e)) {
                Payment::whereKey($this->paymentId)->whereNull('telegram_notified_at')->update(['telegram_notified_at' => now()]);

                return;
            }

            // Не бросаем исключение в общий обработчик: он сам пишет в Telegram.
            if ($this->job && $this->attempts() < $this->tries) {
                try {
                    $this->release($this->attempts() * 60);
                } catch (Throwable $releaseError) {
                    TelegramFailure::record($releaseError, ['topic' => 'payments', 'payment_id' => $this->paymentId, 'stage' => 'retry']);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        TelegramFailure::record($exception ?? new RuntimeException('Задание уведомления завершилось аварийно.'), [
            'topic' => 'payments', 'payment_id' => $this->paymentId, 'stage' => 'worker',
        ]);
    }
}
