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
// Посылки возвратов от покупателей: где они и пришли ли к нам.
Schedule::command('returns:sync')->everyThirtyMinutes()->withoutOverlapping(30);
// «Обновить пункты выдачи» в админке: заявку забираем раз в минуту.
Schedule::command('delivery:points --requested')->everyMinute()->withoutOverlapping(60)->runInBackground();
// Второй чек «полный расчёт» с КИЗ полученным заказам; итог чеков и возвратов в обработке.
Schedule::command('receipts:sync')->everyFiveMinutes()->withoutOverlapping(10);
// Рассылки клиентам: по 30 писем в минуту.
Schedule::command('mailings:send')->everyMinute()->withoutOverlapping(10);
