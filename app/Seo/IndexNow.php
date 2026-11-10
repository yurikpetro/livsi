<?php

namespace App\Seo;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * IndexNow — сайт сам говорит Яндексу «эта страница изменилась».
 *
 * Без этого робот узнаёт о новом товаре или цене, когда придёт сам, —
 * через дни или недели. С ним страница попадает в очередь на обход за
 * минуты. Изменения копятся (товар правят по несколько раз подряд) и
 * уходят пачкой раз в 10 минут командой seo:indexnow.
 *
 * Работает только на боевом сайте с настоящим доменом: копия сервера и
 * разработка Яндекс не беспокоят.
 */
class IndexNow
{
    public const ENDPOINT = 'https://yandex.com/indexnow';

    private const PENDING = 'indexnow:pending';

    /** Ключ сайта: создаётся один раз и хранится в настройках. */
    public static function key(): string
    {
        $key = (string) Setting::get('indexnow_key', '');
        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = Str::lower(bin2hex(random_bytes(16)));
            Setting::put('indexnow_key', $key);
        }

        return $key;
    }

    /** Боевой сайт с доменом (не IP, не localhost) и включённая отправка. */
    public static function enabled(): bool
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return app()->environment('production')
            && str_starts_with((string) config('app.url'), 'https://')
            && str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)
            && Setting::get('indexnow_enabled', true) !== false;
    }

    /** Отложить адрес до ближайшей отправки. */
    public static function queue(string ...$urls): void
    {
        if ($urls === [] || ! self::enabled()) {
            return;
        }
        // Адрес мог собраться с хоста запроса (админку открыли не по главному адресу) — приводим к APP_URL.
        $urls = array_values(array_filter(array_map(fn (string $url): ?string => self::normalize($url), $urls)));
        if ($urls === []) {
            return;
        }
        try {
            Cache::lock(self::PENDING.':lock', 5)->block(3, function () use ($urls): void {
                $pending = (array) Cache::get(self::PENDING, []);
                Cache::put(self::PENDING, array_values(array_unique(array_merge($pending, $urls))), now()->addDay());
            });
        } catch (Throwable $exception) {
            Log::info('IndexNow: адрес не поставлен в очередь.', ['error' => mb_substr($exception->getMessage(), 0, 200)]);
        }
    }

    /** Забрать накопленные адреса. @return list<string> */
    public static function takePending(): array
    {
        return (array) Cache::lock(self::PENDING.':lock', 5)->block(3, function (): array {
            $pending = (array) Cache::pull(self::PENDING, []);

            return array_values(array_unique($pending));
        });
    }

    /**
     * Отправить адреса Яндексу. Возвращает код ответа: 200/202 — приняты,
     * 403 — Яндекс не нашёл ключ на сайте, 422 — адреса не с этого сайта.
     *
     * @param  list<string>  $urls
     */
    public static function submit(array $urls): ?int
    {
        if (! self::enabled()) {
            return null;
        }
        $urls = array_values(array_unique(array_filter(array_map(fn (string $url): ?string => self::normalize($url), $urls))));
        if ($urls === []) {
            return null;
        }
        $root = rtrim((string) config('app.url'), '/');
        $status = null;
        foreach (array_chunk($urls, 10000) as $chunk) {
            $response = Http::timeout(15)->acceptJson()->post(self::ENDPOINT, [
                'host' => parse_url($root, PHP_URL_HOST),
                'key' => self::key(),
                'keyLocation' => $root.'/indexnow.txt',
                'urlList' => $chunk,
            ]);
            $status = $response->status();
            if (! in_array($status, [200, 202], true)) {
                Log::warning('IndexNow: Яндекс не принял адреса.', ['status' => $status, 'body' => mb_substr($response->body(), 0, 300)]);
            }
        }

        return $status;
    }

    /** Тот же путь, но на главном адресе сайта. */
    private static function normalize(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
