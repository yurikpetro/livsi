<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramFailure
{
    /** Сбой доставки не должен возвращаться в Telegram через общий стек логов. */
    public static function record(Throwable $reason, array $context = []): void
    {
        try {
            $channel = (string) config('livsi.telegram.fallback_channel', 'daily');
            if (! in_array(config("logging.channels.{$channel}.driver"), ['single', 'daily'], true)) {
                $channel = 'daily';
            }

            Log::channel($channel)->warning('Оповещение в Телеграм не ушло.', $context + [
                'причина' => ErrorReport::redact($reason->getMessage()),
            ]);
        } catch (Throwable) {
            // Даже недоступный журнал не должен прерывать оплату или обработку ошибки.
            @error_log('LIVSI: не удалось записать сбой уведомления Telegram в файловый журнал.');
        }
    }
}
