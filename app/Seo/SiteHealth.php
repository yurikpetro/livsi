<?php

namespace App\Seo;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Открыт ли сайт для поисковиков — глазами робота Яндекса.
 *
 * Сайт запрашивает сам себя по публичному адресу (через nginx), поэтому
 * видит то же, что робот: заголовки сервера, robots.txt, карту сайта.
 * Ровно так в октябре 2026 потерялся весь сайт: в nginx остался
 * X-Robots-Tag: noindex со времён закрытого сайта, и никто этого не видел.
 */
final class SiteHealth
{
    public const UA = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';

    /**
     * @return array{ok: bool, problems: list<string>, checked: list<string>}
     */
    public static function check(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $problems = [];
        $checked = [];

        // Главная и каталог: 200 и ни одного «noindex».
        foreach (['/' => 'Главная', '/catalog' => 'Каталог'] as $path => $name) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::UA])->withoutRedirecting()->timeout(15)->get($base.$path);
            } catch (Throwable $e) {
                $problems[] = "{$name}: сайт не ответил роботу ({$e->getMessage()}).";

                continue;
            }
            $checked[] = "{$name}: код {$response->status()}";
            if ($response->status() !== 200) {
                $problems[] = "{$name} отвечает роботу кодом {$response->status()}, а не 200.";
            }
            $header = mb_strtolower(trim($response->header('X-Robots-Tag')));
            if (str_contains($header, 'noindex') || str_contains($header, 'none')) {
                $problems[] = "{$name}: сервер отдаёт заголовок X-Robots-Tag «".$header.'» — поисковики не будут показывать страницу. Проверьте настройки nginx (add_header X-Robots-Tag).';
            }
            if (preg_match('/<meta[^>]+name=["\']?(robots|yandex)["\']?[^>]*content=["\'][^"\']*(noindex|none)/i', (string) $response->body())) {
                $problems[] = "{$name}: в коде страницы стоит метатег robots с noindex.";
            }
        }

        // robots.txt: не закрыт целиком и знает про карту сайта.
        try {
            $robots = Http::withHeaders(['User-Agent' => self::UA])->timeout(15)->get($base.'/robots.txt');
            $text = (string) $robots->body();
            $checked[] = 'robots.txt: код '.$robots->status();
            if ($robots->status() !== 200) {
                $problems[] = 'robots.txt отвечает кодом '.$robots->status().'.';
            } elseif (self::closesEverything($text)) {
                $problems[] = 'robots.txt закрывает весь сайт (Disallow: /) для Яндекса.';
            }
            if ($robots->status() === 200 && ! preg_match('/^\s*Sitemap:\s*\S+/mi', $text)) {
                $problems[] = 'В robots.txt нет строки Sitemap — Яндекс хуже находит новые страницы.';
            }
        } catch (Throwable $e) {
            $problems[] = 'robots.txt не открылся: '.$e->getMessage();
        }

        // Карта сайта: открывается и не пустая.
        try {
            $sitemap = Http::withHeaders(['User-Agent' => self::UA])->timeout(20)->get($base.'/sitemap.xml');
            $count = substr_count((string) $sitemap->body(), '<loc>');
            $checked[] = 'sitemap.xml: код '.$sitemap->status().', адресов '.$count;
            if ($sitemap->status() !== 200 || $count === 0) {
                $problems[] = 'Карта сайта /sitemap.xml не открывается или пустая.';
            }
        } catch (Throwable $e) {
            $problems[] = 'Карта сайта не открылась: '.$e->getMessage();
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'checked' => $checked];
    }

    /** Есть ли для Яндекса (или для всех) правило Disallow: / без исключений. */
    private static function closesEverything(string $robots): bool
    {
        $agents = [];
        $applies = false;
        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if ($line === '') {
                $agents = [];

                continue;
            }
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                $agents[] = mb_strtolower(trim($m[1]));
                $applies = in_array('*', $agents, true) || in_array('yandex', $agents, true);

                continue;
            }
            if ($applies && preg_match('/^disallow:\s*\/\s*$/i', $line)) {
                return true;
            }
        }

        return false;
    }
}
