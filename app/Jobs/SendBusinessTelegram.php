<?php

namespace App\Jobs;

use App\Monitoring\BusinessReport;
use App\Monitoring\Telegram;
use App\Monitoring\TelegramFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendBusinessTelegram implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** Сколько ждать ответа Телеграма: задание фоновое, торопиться некуда. */
    public const WAIT = 20.0;

    public function __construct(public string $kind, public int $recordId, public string $topic, public ?string $detail = null) {}

    public function handle(Telegram $telegram, BusinessReport $report): void
    {
        try {
            if (! $telegram->topic($this->topic)) {
                throw new RuntimeException("Не задан топик Telegram: {$this->topic}.");
            }

            $message = $report->render($this->kind, $this->recordId, $this->detail);
            if ($message === null) {
                return;
            }

            $telegram->send($message, $this->topic, self::WAIT);
        } catch (Throwable $e) {
            TelegramFailure::record($e, ['topic' => $this->topic, 'kind' => $this->kind, 'record_id' => $this->recordId, 'attempt' => $this->attempts()]);

            // Запрос ушёл, ответ не дождались — сообщение, скорее всего, в группе. Не дублируем.
            if ($this->job && $this->attempts() < $this->tries && ! Telegram::maybeDelivered($e)) {
                try {
                    $this->release($this->attempts() * 60);
                } catch (Throwable $releaseError) {
                    TelegramFailure::record($releaseError, ['topic' => $this->topic, 'record_id' => $this->recordId, 'stage' => 'retry']);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        TelegramFailure::record($exception ?? new RuntimeException('Задание Telegram завершилось аварийно.'), [
            'topic' => $this->topic, 'kind' => $this->kind, 'record_id' => $this->recordId, 'stage' => 'worker',
        ]);
    }
}
