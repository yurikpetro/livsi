<?php

namespace App\Console\Commands;

use App\Http\Controllers\RobotsController;
use Illuminate\Console\Command;

/**
 * Пишет public/robots.txt с адресом сайта из APP_URL. Файлом, а не только
 * маршрутом: веб-сервер часто отдаёт robots.txt сам, не спрашивая сайт.
 */
class SeoRobots extends Command
{
    protected $signature = 'seo:robots';

    protected $description = 'Записать public/robots.txt с картой сайта по адресу из APP_URL';

    public function handle(): int
    {
        $text = RobotsController::text();
        if (file_put_contents(public_path('robots.txt'), $text) === false) {
            $this->error('Не удалось записать public/robots.txt');

            return self::FAILURE;
        }
        $this->line(trim($text));

        return self::SUCCESS;
    }
}
