<?php

namespace App\Console\Commands;

use App\Mail\OrderPlaced;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Проверка почты.
 *
 * Нужна ровно один раз — когда в окружение вписаны настройки почтового
 * ящика и надо понять, уходит ли письмо, до того как этого не дождётся
 * первый покупатель. Тот же смысл, что у `payments:check`.
 *
 * Пароль здесь не спрашивается и нигде не печатается: он живёт в `.env`
 * и в вывод команды попасть не должен.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {--to= : Кому отправить пробное письмо}';

    protected $description = 'Проверить настройки почты и отправить пробное письмо';

    public function handle(): int
    {
        $this->line('');

        $mailer = config('mail.default');
        $from   = config('mail.from.address');

        $this->line('Отправитель: ' . $from . ' (' . config('mail.from.name') . ')');
        $this->line('Способ отправки: ' . $mailer);

        if ($mailer === 'log') {
            $this->warn('Письма не уходят наружу, а пишутся в storage/logs/laravel.log — это режим разработки.');
        }

        if ($mailer === 'smtp') {
            $this->line('Сервер: ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));
            $this->line('Логин: ' . (config('mail.mailers.smtp.username') ?: 'не задан'));
        }

        if (blank($from) || str_contains((string) $from, 'example.com')) {
            $this->error('Адрес отправителя не настроен: MAIL_FROM_ADDRESS всё ещё заглушка.');
            $this->line('Письма с чужого домена почтовые службы отклоняют или кладут в спам.');

            return self::FAILURE;
        }

        // Очередь важнее настроек ящика: письма ставятся в неё, и без
        // работника они просто копятся в таблице, ничем себя не выдавая.
        if (config('queue.default') !== 'sync') {
            $this->line('');
            $this->warn('Письма ставятся в очередь (' . config('queue.default') . ').');
            $this->line('Без запущенного `php artisan queue:work` они не уйдут, а останутся в таблице jobs.');
        }

        if (! $to = $this->option('to')) {
            $this->line('');
            $this->line('Чтобы отправить пробное письмо: php artisan mail:check --to=адрес@домен');
            $this->line('');

            return self::SUCCESS;
        }

        // Пробуем настоящим письмом, а не «здравствуйте»: так сразу видно
        // и вёрстку, и подстановку реквизитов, и ссылку на заказ.
        $order = Order::with('items')->latest('id')->first();

        if (! $order) {
            $this->error('Нет ни одного заказа — не на чем показать письмо. Оформите тестовый заказ.');

            return self::FAILURE;
        }

        try {
            // Отправляем прямо сейчас, а не через очередь: команда должна
            // сказать о сбое, а не молча положить задание в таблицу.
            Mail::to($to)->send(new OrderPlaced($order));
        } catch (Throwable $e) {
            $this->error('Письмо не ушло: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'authenticate') || str_contains($e->getMessage(), '535')) {
                $this->line('');
                $this->line('Похоже на отказ авторизации. У Яндекса обычный пароль от аккаунта не подходит —');
                $this->line('нужен пароль приложения, созданный в настройках безопасности Яндекс ID.');
            }

            return self::FAILURE;
        }

        $this->info('Письмо по заказу ' . $order->number . ' отправлено на ' . $to . '.');
        $this->line('');

        return self::SUCCESS;
    }
}
