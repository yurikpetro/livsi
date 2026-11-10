<?php

namespace App\Console\Commands;

use App\Seo\Autopilot;
use Illuminate\Console\Command;

/** SEO-автопилот: проверка сайта, карта сайта, переобход, проблемы Вебмастера. Каждое утро. */
class SeoAutopilot extends Command
{
    protected $signature = 'seo:autopilot {--quiet-telegram : не присылать сообщения в Telegram}';

    protected $description = 'SEO-автопилот: открыт ли сайт для поисковиков, карта сайта и переобход в Вебмастере, серьёзные проблемы';

    public function handle(Autopilot $autopilot): int
    {
        $result = $autopilot->run(notify: ! $this->option('quiet-telegram'));
        foreach ($result['log'] as $line) {
            $this->line($line);
        }

        return $result['health']['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
