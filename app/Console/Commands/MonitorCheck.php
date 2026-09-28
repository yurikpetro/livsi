<?php

namespace App\Console\Commands;

use App\Monitoring\ErrorReport;
use App\Monitoring\Telegram;
use App\Monitoring\TelegramFailure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Проверка оповещений в Телеграм.
 *
 * Настройка бота — это три числа, которые негде подсмотреть: токен, номер
 * чата и номер топика. Ошибиться в любом из них легко, а узнать об этом
 * иначе можно только в день настоящего сбоя, когда сообщение не придёт.
 * Команда проверяет все три и присылает в группу пробное сообщение.
 */
class MonitorCheck extends Command
{
    protected $signature = 'monitor:check {--no-send : только проверить настройки, ничего не отправляя} {--topic=errors : errors, payments, leads, stock, users или issues}';

    protected $description = 'Проверить бота, чат и указанный топик уведомлений';

    public function handle(Telegram $telegram): int
    {
        $this->line('');

        $topicName = (string) $this->option('topic');
        $topics = [
            'errors' => 'Ошибки',
            'payments' => 'Оплаты',
            'leads' => 'Заявки',
            'stock' => 'Остатки',
            'users' => 'Регистрации',
            'issues' => 'Возвраты и доставка',
        ];
        if (! isset($topics[$topicName])) {
            $this->error('Неизвестный топик: '.implode(', ', array_keys($topics)).'.');

            return self::FAILURE;
        }

        if (! $telegram->isConfigured()) {
            $this->error('Оповещения выключены: не заданы TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID.');

            return self::FAILURE;
        }

        try {
            $bot = $telegram->call('getMe');
        } catch (Throwable $e) {
            $this->error('Бот не отвечает: '.ErrorReport::redact($e->getMessage()));
            $this->line('Чаще всего это опечатка в токене или сервер не выпускает наружу.');

            return self::FAILURE;
        }

        $this->info('Бот: @'.($bot['username'] ?? '—'));

        try {
            $chat = $telegram->call('getChat', ['chat_id' => (string) config('livsi.telegram.chat_id')]);
        } catch (Throwable $e) {
            $this->error('Чат недоступен: '.ErrorReport::redact($e->getMessage()));
            $this->line('Бота нужно добавить в группу; номер чата у супергруппы начинается с -100.');

            return self::FAILURE;
        }

        $this->info('Чат: '.($chat['title'] ?? config('livsi.telegram.chat_id')));

        // Без топиков сообщение с message_thread_id уйдёт в никуда
        // с ошибкой, которую легко принять за неверный номер чата.
        if (($chat['is_forum'] ?? false) !== true) {
            $this->warn('В группе выключены топики — сообщения пойдут в общую ленту.');
        }

        $topic = $telegram->topic($topicName);

        if ($topicName !== 'errors' && ! $topic) {
            $this->error('Не задан номер топика для '.$topics[$topicName].'.');

            return self::FAILURE;
        }

        $this->line($topic
            ? 'Топик «'.$topics[$topicName].'»: '.$topic
            : 'Топик не задан (TELEGRAM_TOPIC_ERRORS) — сообщения пойдут в общую ленту.');

        $this->line('Уровень, с которого шлём: '.config('logging.channels.telegram.level'));
        $this->line('Каналы лога: '.implode(', ', config('logging.channels.stack.channels', [])));

        if ($topicName === 'errors' && ! in_array('telegram', config('logging.channels.stack.channels', []), true)) {
            $this->warn('Канал telegram не подключён к стопке — ошибки приложения в группу не пойдут.');
            $this->line('Добавьте его в LOG_STACK, например: LOG_STACK=single,telegram');
        }

        if ($this->option('no-send')) {
            $this->line('');

            return self::SUCCESS;
        }

        try {
            $sample = match ($topicName) {
                'errors' => $this->sample(),
                'payments' => '<b>✅ Проверка связи · Оплаты LIVSI</b>'."\n\n".'Сюда будут приходить подтверждённые оплаты заказов. Это проверка доставки, реальной оплаты не было.',
                default => '<b>✅ Проверка связи · '.ErrorReport::escape($topics[$topicName]).' LIVSI</b>'
                    ."\n\n".'Это проверка доставки уведомлений. Реального события не было.',
            };
            $telegram->send($sample, $topicName);
        } catch (Throwable $e) {
            TelegramFailure::record($e, ['topic' => $topicName, 'stage' => 'check']);
            $this->error('Сообщение не ушло: '.ErrorReport::redact($e->getMessage()));
            $this->line('Если жалуется на message thread — проверьте номер топика в ссылке на него.');

            return self::FAILURE;
        }

        $this->info('Пробное сообщение отправлено — посмотрите в топик.');
        $this->line('');

        return self::SUCCESS;
    }

    /** Нарочно не похоже на настоящий сбой: чтобы никто не бросился чинить. */
    private function sample(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'сайт';

        return '<b>'.ErrorReport::escape('✅ Проверка связи · '.$host).'</b>'
            ."\n\n"
            .ErrorReport::escape('Оповещения о сбоях настроены. Сюда будут приходить ошибки сайта: '
                .'отказы оплаты, недоступная база, упавшие письма и всё, что приложение запишет в журнал как ошибку.')
            ."\n\n"
            .ErrorReport::escape(now()->timezone('Europe/Moscow')->format('d.m.Y H:i:s').' МСК');
    }
}
