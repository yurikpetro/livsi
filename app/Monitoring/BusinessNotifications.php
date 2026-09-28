<?php

namespace App\Monitoring;

use App\Jobs\SendBusinessTelegram;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BusinessNotifications
{
    public function queue(string $kind, int $recordId, string $topic, ?string $detail = null): void
    {
        try {
            if (! app(Telegram::class)->isConfigured()) {
                return;
            }

            if (! app(Telegram::class)->topic($topic)) {
                throw new RuntimeException("Не задан топик Telegram: {$topic}.");
            }

            DB::afterCommit(function () use ($kind, $recordId, $topic, $detail): void {
                try {
                    SendBusinessTelegram::dispatch($kind, $recordId, $topic, $detail)
                        ->onConnection(config('livsi.telegram.payments_connection', 'database'));
                } catch (Throwable $e) {
                    TelegramFailure::record($e, ['topic' => $topic, 'kind' => $kind, 'record_id' => $recordId, 'stage' => 'dispatch']);
                }
            });
        } catch (Throwable $e) {
            TelegramFailure::record($e, ['topic' => $topic, 'kind' => $kind, 'record_id' => $recordId, 'stage' => 'prepare']);
        }
    }
}
