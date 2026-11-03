<?php

namespace App\Console\Commands;

use App\Services\MailingService;
use Illuminate\Console\Command;

/** Рассылки: очередная пачка писем — раз в минуту по расписанию. */
class MailingsSend extends Command
{
    protected $signature = 'mailings:send {--limit=30 : писем за запуск}';

    protected $description = 'Отправить очередную пачку писем рассылок';

    public function handle(MailingService $mailings): int
    {
        $sent = $mailings->sendBatch(max(1, min(200, (int) $this->option('limit'))));
        if ($sent) {
            $this->info('Писем отправлено: '.$sent);
        }

        return self::SUCCESS;
    }
}
