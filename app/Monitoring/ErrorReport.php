<?php

namespace App\Monitoring;

use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Throwable;

/**
 * Запись лога, превращённая в сообщение для Телеграма.
 *
 * Задача — чтобы по одному сообщению было понятно, идти чинить прямо
 * сейчас или можно утром: что сломалось, у кого, на каком шаге и сколько
 * раз повторилось. Поэтому кроме текста ошибки в сообщение попадают адрес
 * запроса, покупатель и первые строки стека по нашему коду.
 */
class ErrorReport
{
    /** Подпись уровня. Цвет кружка — чтобы отличать с одного взгляда в ленте. */
    private const BADGES = [
        'EMERGENCY' => '🆘 Всё лежит',
        'ALERT' => '🆘 Тревога',
        'CRITICAL' => '🔥 Критично',
        'ERROR' => '🔴 Ошибка',
        'WARNING' => '🟡 Предупреждение',
        'NOTICE' => '🔵 Заметка',
        'INFO' => '🔵 Сообщение',
        'DEBUG' => '⚪ Отладка',
    ];

    public function __construct(private readonly LogRecord $record) {}

    /**
     * Отпечаток ошибки: одинаковые не должны сыпаться в группу очередью.
     *
     * Числа из текста выброшены намеренно. Сообщение упавшего запроса
     * к базе содержит подставленные значения, и без этого один и тот же
     * сбой на каждом заказе выглядел бы как новый.
     */
    public function fingerprint(): string
    {
        $exception = $this->exception();

        return sha1(implode('|', [
            $this->record->level->getName(),
            preg_replace('/\d+/', '0', $this->record->message),
            $exception ? $exception::class : '',
            $exception ? $exception->getFile().':'.$exception->getLine() : '',
        ]));
    }

    public function toHtml(int $repeats = 0): string
    {
        $exception = $this->exception();
        $out = [];

        $out[] = '<b>'.self::escape($this->badge().' · '.$this->site()).'</b>';
        $out[] = '';
        $out[] = self::escape(Str::limit($this->record->message, 600));

        if ($exception) {
            $out[] = '';
            $out[] = '<code>'.self::escape($exception::class).'</code>';
            $out[] = '<code>'.self::escape($this->shorten($exception->getFile()).':'.$exception->getLine()).'</code>';
        }

        $out[] = '';
        $out[] = $this->where();

        if ($details = $this->details()) {
            $out[] = '';
            $out[] = '<b>Подробности</b>';
            $out[] = '<pre>'.self::escape($details).'</pre>';
        }

        if ($exception && $trace = $this->trace($exception)) {
            $out[] = '';
            $out[] = '<b>Наш код в стеке</b>';
            $out[] = '<pre>'.self::escape($trace).'</pre>';
        }

        $out[] = '';
        $out[] = self::escape($this->when().($repeats > 0 ? ' · и ещё '.$repeats.' таких же' : ''));

        return self::redact(implode("\n", $out));
    }

    /** Телеграм разбирает разметку сам, поэтому угловые скобки экранируем. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Вычистить секреты.
     *
     * Ключи проскакивают в текст ошибки сами: токен бота стоит в адресе
     * запроса к Телеграму, пароль базы — в сообщении драйвера PDO. Группу
     * читает заказчик, а сообщения в Телеграме хранятся вечно.
     */
    public static function redact(string $text): string
    {
        $secrets = [
            config('livsi.telegram.token'),
            config('livsi.yookassa.secret_key'),
            config('livsi.yandex.client_secret'),
            config('database.connections.'.config('database.default').'.password'),
            config('mail.mailers.smtp.password'),
            config('app.key'),
            ...\App\Payments\PaymentSettings::allSecrets(),
        ];

        foreach ($secrets as $secret) {
            if (is_string($secret) && mb_strlen($secret) > 7) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    private function exception(): ?Throwable
    {
        $exception = $this->record->context['exception'] ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    private function badge(): string
    {
        return self::BADGES[$this->record->level->getName()] ?? $this->record->level->getName();
    }

    /** Домен вместо названия: боевой контур от временного отличается адресом. */
    private function site(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'сайт';

        return app()->environment('production') ? $host : $host.' ['.app()->environment().']';
    }

    private function where(): string
    {
        if (app()->runningInConsole()) {
            $argv = implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 4));

            return '<b>Где</b> '.self::escape('консоль'.($argv ? ': '.$argv : ''));
        }

        $request = request();

        $lines = ['<b>Где</b> '.self::escape($request->method().' '.$request->fullUrl())];

        // Пользователя спрашиваем, только если он уже загружен: запрос
        // к базе отсюда упал бы ровно там, где сбой и так в базе.
        $user = null;

        try {
            $user = Auth::hasUser() ? Auth::user() : null;
        } catch (Throwable) {
            // Кто именно — знать приятно, но терять из-за этого сообщение глупо.
        }

        $lines[] = '<b>Кто</b> '.self::escape(($user?->email ?? 'гость').' · '.$request->ip());

        return implode("\n", $lines);
    }

    /** Остальной контекст записи — то, что передали вторым доводом в Log::error(). */
    private function details(): string
    {
        $rows = [];

        foreach ($this->record->context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }

            $rows[] = $key.': '.$this->stringify((string) $key, $value);
        }

        return Str::limit(implode("\n", $rows), 900);
    }

    private function stringify(string $key, mixed $value): string
    {
        if (preg_match('/pass|secret|token|key|auth|cookie|signature/i', $key)) {
            return '***';
        }

        return match (true) {
            is_bool($value) => $value ? 'да' : 'нет',
            is_null($value) => '—',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    /**
     * Только наши файлы: полный стек — это полсотни строк фреймворка,
     * в которых искомые две теряются, а в сообщение они всё равно не влезут.
     */
    private function trace(Throwable $exception): string
    {
        $rows = [];

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if (! $file || ! str_starts_with($file, base_path()) || str_contains($file, 'vendor')) {
                continue;
            }

            $rows[] = $this->shorten($file).':'.($frame['line'] ?? '?');

            if (count($rows) >= 6) {
                break;
            }
        }

        return implode("\n", $rows);
    }

    private function shorten(string $path): string
    {
        return str_replace('\\', '/', Str::after($path, base_path().DIRECTORY_SEPARATOR));
    }

    /**
     * Время по Москве. Приложение живёт в UTC, а разбираться со сбоем
     * будут по часам на стене — разница в три часа при сверке с логами
     * и звонками покупателей стоит дороже, чем эта строчка.
     */
    private function when(): string
    {
        return $this->record->datetime
            ->setTimezone(new DateTimeZone('Europe/Moscow'))
            ->format('d.m.Y H:i:s').' МСК';
    }
}
