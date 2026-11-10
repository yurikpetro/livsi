<?php

namespace App\Console\Commands;

use App\Http\Controllers\SitemapController;
use App\Seo\IndexNow;
use Illuminate\Console\Command;
use Throwable;

/**
 * Отправить Яндексу изменившиеся страницы (раз в 10 минут по расписанию)
 * или весь сайт целиком (--all) — после установки и больших правок.
 */
class SeoIndexNow extends Command
{
    protected $signature = 'seo:indexnow {--all : отправить все страницы из карты сайта}';

    protected $description = 'Сообщить Яндексу (IndexNow) об изменившихся страницах';

    public function handle(): int
    {
        if (! IndexNow::enabled()) {
            $this->line('IndexNow выключен: не боевой сайт или адрес без https-домена.');

            return self::SUCCESS;
        }
        $urls = $this->option('all') ? array_column(SitemapController::urls(), 'loc') : IndexNow::takePending();
        if ($urls === []) {
            $this->line('Новых изменений нет.');

            return self::SUCCESS;
        }
        try {
            $status = IndexNow::submit($urls);
        } catch (Throwable $exception) {
            // Не дошли до Яндекса — вернём адреса в очередь, отправим в следующий раз.
            if (! $this->option('all')) {
                IndexNow::queue(...$urls);
            }
            $this->warn('Яндекс не ответил: '.mb_substr($exception->getMessage(), 0, 200));

            return self::SUCCESS;
        }
        $this->line('Отправлено адресов: '.count($urls).', ответ Яндекса: '.($status ?? '—')
            .(in_array($status, [200, 202], true) ? ' (принято)' : ''));

        return self::SUCCESS;
    }
}
