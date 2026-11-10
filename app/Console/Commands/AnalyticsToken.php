<?php

namespace App\Console\Commands;

use App\Analytics\AnalyticsException;
use App\Analytics\YandexOAuth;
use Illuminate\Console\Command;

/**
 * Продлевает доступ к Метрике и Вебмастеру заранее, даже если раздел
 * «Аналитика сайта» месяцами никто не открывает.
 */
class AnalyticsToken extends Command
{
    protected $signature = 'analytics:token';

    protected $description = 'Продлить доступ к Яндексу для раздела «Аналитика сайта», если срок подходит к концу';

    public function handle(YandexOAuth $oauth): int
    {
        if (! $oauth->connected()) {
            $this->line('Яндекс не подключён — нечего продлевать.');

            return self::SUCCESS;
        }

        try {
            $oauth->accessToken();
        } catch (AnalyticsException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Доступ действует до '.($oauth->expiresAt()?->timezone('Europe/Moscow')->format('d.m.Y') ?? 'неизвестно').'.');

        return self::SUCCESS;
    }
}
