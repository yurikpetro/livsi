<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:expire-reserves')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shipments:process')->everyMinute()->withoutOverlapping();

// Прямые службы доставки: пункты выдачи — ночью по Москве, когда заказов
// почти нет; статусы отправлений — каждые 15 минут.
Schedule::command('delivery:points')->dailyAt('00:20')->withoutOverlapping(120);
Schedule::command('delivery:statuses')->everyFifteenMinutes()->withoutOverlapping(30);
// «Обновить пункты выдачи» в админке: заявку забираем раз в минуту.
Schedule::command('delivery:points --requested')->everyMinute()->withoutOverlapping(60)->runInBackground();
