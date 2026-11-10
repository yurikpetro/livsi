<?php

namespace App\Console\Commands;

use App\Monitoring\Telegram;
use App\Seo\SeoReport;
use Illuminate\Console\Command;
use Throwable;

/** Недельная сводка о поиске и заказах — в Telegram по понедельникам. */
class SeoWeekly extends Command
{
    protected $signature = 'seo:weekly {--print : только показать, не отправлять}';

    protected $description = 'Недельная сводка: поиск Яндекса, посещаемость, заказы по источникам, битые ссылки';

    public function handle(SeoReport $report, Telegram $telegram): int
    {
        $text = $report->weekly();
        if ($this->option('print')) {
            $this->line(strip_tags($text));

            return self::SUCCESS;
        }
        try {
            $telegram->send($text, 'seo', 20.0);
            $this->info('Сводка отправлена в Telegram.');
        } catch (Throwable $e) {
            $this->warn('Не отправилось: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
