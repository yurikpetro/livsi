<?php

namespace App\Monitoring;

use Illuminate\Support\Facades\Cache;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Канал лога, который вместо файла пишет в Телеграм.
 *
 * Подключён к общей стопке каналов, а не вызывается руками, и в этом весь
 * смысл: в Телеграм попадает всё, что приложение и так считает ошибкой, —
 * необработанные исключения, упавшие задания очереди, отказы провайдера
 * оплаты, — без единой строчки в местах, где эти ошибки возникают.
 *
 * Отправка идёт прямо в запросе, а не через очередь. Очередь у нас лежит
 * в базе, и первая же ошибка, о которой действительно надо узнать, —
 * недоступная база — унесла бы с собой и уведомление о себе.
 */
class TelegramLogHandler extends AbstractProcessingHandler
{
    /** Кольцо: отправка сама может что-нибудь записать в лог. */
    private static bool $busy = false;

    public function __construct(
        private readonly Telegram $telegram,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$busy || ! $this->telegram->isConfigured()) {
            return;
        }

        self::$busy = true;

        try {
            $report = new ErrorReport($record);
            $key = 'monitor:alert:'.$report->fingerprint();

            if ($this->firstInWindow($key)) {
                $this->telegram->send($report->toHtml($this->repeats($key)), 'errors');
            }
        } catch (Throwable $e) {
            // Наружу ошибку выпускать нельзя: у стопки каналов
            // ignore_exceptions выключен, и падение обработчика уронило бы
            // запрос покупателя. Но и молчать нельзя — несработавшее
            // оповещение не оставляло бы следа вообще, а заводили его
            // ровно против такой тишины. Пишем в файловый канал напрямую,
            // мимо стопки: через неё вернулись бы сюда же.
            TelegramFailure::record($e, ['topic' => 'errors']);
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Первая ли это такая ошибка за окно молчания.
     *
     * Без окна один сломанный шаг оформления превращается в сотню
     * одинаковых сообщений: группу перестают читать ровно в тот день,
     * когда она нужнее всего.
     */
    private function firstInWindow(string $key): bool
    {
        $window = (int) config('livsi.telegram.repeat_after', 600);

        // Ноль означает «слать всё подряд», а не «молчать вечно»:
        // Cache::add с нулевым сроком возвращает false, и окно, заданное
        // нулём по недосмотру, отключило бы оповещения целиком.
        if ($window < 1) {
            return true;
        }

        try {
            $first = Cache::add($key, 1, $window);
        } catch (Throwable) {
            // Кэш недоступен — шлём. Лишнее сообщение лучше тишины.
            return true;
        }

        if (! $first) {
            try {
                // Счётчик живёт дольше окна: его показывают в следующем
                // сообщении, чтобы масштаб сбоя был виден.
                Cache::add($key.':repeats', 0, $window * 6);
                Cache::increment($key.':repeats');
            } catch (Throwable) {
                // Счёт повторов — приятная мелочь, не повод падать.
            }
        }

        return $first;
    }

    private function repeats(string $key): int
    {
        try {
            return (int) Cache::pull($key.':repeats', 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
