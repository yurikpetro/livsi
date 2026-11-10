<?php

namespace App\Seo;

use App\Analytics\AnalyticsException;
use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use App\Http\Controllers\SitemapController;
use App\Models\Setting;
use App\Monitoring\Telegram;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEO-автопилот: каждое утро делает то, что раньше делали руками
 * в Вебмастере, и докладывает, если что-то не так.
 *
 *  1. Проверяет, что сайт открыт для поисковиков (SiteHealth). Закрыт —
 *     сообщение в Telegram в тот же день, а не через месяц.
 *  2. Карта сайта в Вебмастере — ровно наша, старые убирает.
 *  3. Переобход: главная и каталог раз в неделю; старые адреса из выдачи,
 *     которые теперь ведут на новые страницы или пропали, — чтобы Яндекс
 *     быстрее заменил их; страницы из карты, которых ещё нет в поиске.
 *     Тратит не больше 80 % суточной квоты: остальное — для ручного переобхода.
 *  4. Серьёзные проблемы из «Диагностики» Вебмастера — в Telegram, когда
 *     появляются и когда Яндекс их снимает.
 */
final class Autopilot
{
    private const STATE = 'seo_autopilot';

    /** Страницу, отправленную на переобход, повторно не шлём столько дней. */
    private const RESEND_DAYS = 14;

    /** Главную и каталог — раз в неделю. */
    private const MAIN_DAYS = 7;

    /** Сколько адресов из поиска проверять за запуск. */
    private const SCAN = 200;

    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private readonly YandexOAuth $oauth,
        private readonly Webmaster $webmaster,
        private readonly Telegram $telegram,
    ) {}

    /** @return array<string, mixed> */
    public static function state(): array
    {
        return (array) Setting::get(self::STATE, []);
    }

    /** @return array{log: list<string>, health: array<string, mixed>} */
    public function run(bool $notify = true): array
    {
        $state = self::state();

        // 1. Открыт ли сайт.
        $health = SiteHealth::check();
        $this->line($health['ok'] ? 'Сайт открыт для поисковиков: '.implode(', ', $health['checked']).'.' : 'САЙТ ЗАКРЫТ ДЛЯ ПОИСКОВИКОВ.');
        if (! $health['ok'] && $notify) {
            $this->notify("🚨 <b>livsi.shop: поисковики не могут нормально видеть сайт</b>\n"
                .implode("\n", array_map(fn (string $p): string => '• '.e($p), $health['problems']))
                ."\n\nПока это не исправлено, Яндекс убирает страницы из выдачи. Подробнее — админка → «Аналитика сайта» → «Поиск Яндекса».");
        }

        if ($this->oauth->connected()) {
            Webmaster::forget(); // свежие данные, а не сохранённые для админки час назад
            $this->step('Карта сайта', fn () => $this->sitemaps());
            // Не стрелочные функции: им нужно менять $state, а не его копию.
            $this->step('Переобход', function () use (&$state): void {
                $state['recrawl'] = $this->recrawl((array) ($state['recrawl'] ?? []));
            });
            $this->step('Диагностика', function () use (&$state, $notify): void {
                $state['serious'] = $this->diagnostics((array) ($state['serious'] ?? []), $notify);
            });
        } else {
            $this->line('Яндекс к админке не подключён — переобход и карта сайта не трогаются.');
        }

        $state['last_run_at'] = now()->toIso8601String();
        $state['log'] = $this->log;
        $state['health'] = $health + ['at' => now()->toIso8601String()];
        Setting::put(self::STATE, $state);

        return ['log' => $this->log, 'health' => $health];
    }

    // ------------------------------------------------------------- шаги

    private function sitemaps(): void
    {
        $ours = self::absolute(route('sitemap', absolute: false));
        $list = $this->webmaster->userSitemaps();
        $has = false;
        foreach ($list as $sitemap) {
            if (rtrim($sitemap['url'], '/') === $ours) {
                $has = true;

                continue;
            }
            // Чужая или старая карта (прежний сайт, www, http) — убираем: у сайта одна карта.
            $this->line($this->webmaster->deleteSitemap($sitemap['id'])
                ? 'Убрал из Вебмастера старую карту сайта '.$sitemap['url'].'.'
                : 'Не смог убрать старую карту '.$sitemap['url'].'.');
        }
        if (! $has) {
            $result = $this->webmaster->addSitemap($ours);
            $this->line(match ($result) {
                'sent' => 'Добавил в Вебмастер карту сайта '.$ours.'.',
                'already' => 'Карта сайта '.$ours.' уже была в Вебмастере.',
                default => 'Не смог добавить карту сайта '.$ours.' в Вебмастер.',
            });
        } else {
            $this->line('Карта сайта в Вебмастере в порядке.');
        }
    }

    /**
     * @param  array<string, string>  $sent  адрес → когда отправлен
     * @return array<string, string>
     */
    private function recrawl(array $sent): array
    {
        // Старые отметки не копим.
        $sent = array_filter($sent, fn (string $at): bool => Carbon::parse($at)->gt(now()->subDays(30)));
        $quota = $this->webmaster->recrawlQuota();
        $budget = (int) floor($quota['left'] * 0.8);
        if ($budget <= 0) {
            $this->line("Переобход: квота на сегодня исчерпана ({$quota['left']} из {$quota['daily']}).");

            return $sent;
        }

        $fresh = fn (string $url, int $days): bool => ! isset($sent[$url]) || Carbon::parse($sent[$url])->lt(now()->subDays($days));
        $candidates = [];

        foreach ([route('home', absolute: false) => 'главная', route('catalog.index', absolute: false) => 'каталог'] as $path => $label) {
            $url = self::absolute($path);
            if ($fresh($url, self::MAIN_DAYS)) {
                $candidates[$url] = $label.', раз в неделю';
            }
        }

        // Адреса, которые Яндекс держит в поиске, а сайт по ним больше не отвечает
        // страницей: переадресация на новую или 404. Переобход заменит их в выдаче.
        $inSearch = [];
        for ($offset = 0; $offset < self::SCAN; $offset += 100) {
            $page = $this->webmaster->inSearch(100, $offset);
            foreach ($page as $row) {
                $inSearch[] = $row['url'];
            }
            if (count($page) < 100) {
                break;
            }
        }
        foreach ($inSearch as $url) {
            if (! $fresh($url, self::RESEND_DAYS) || isset($candidates[$url])) {
                continue;
            }
            $status = self::status($url);
            if (in_array($status, [301, 302, 307, 308, 404, 410], true)) {
                $candidates[$url] = $status >= 400 ? 'старый адрес, страницы нет' : 'старый адрес, ведёт на новую страницу';
            }
        }

        // Страницы из карты сайта, которых ещё нет в поиске: сначала важные
        // (разделы, товары), документы — в конце.
        $known = array_flip(array_map(fn (string $u): string => rtrim($u, '/'), $inSearch));
        $map = SitemapController::urls();
        uksort($map, fn (int $a, int $b): int => [-(float) $map[$a]['priority'], $a] <=> [-(float) $map[$b]['priority'], $b]);
        foreach ($map as $row) {
            $url = self::absolute((string) parse_url((string) $row['loc'], PHP_URL_PATH));
            if (! isset($known[rtrim($url, '/')]) && $fresh($url, self::RESEND_DAYS) && ! isset($candidates[$url])) {
                $candidates[$url] = 'новая страница, ещё не в поиске';
            }
        }

        $done = 0;
        $already = 0;
        foreach ($candidates as $url => $why) {
            if ($done >= $budget) {
                break;
            }
            $result = $this->webmaster->recrawl($url);
            if ($result['status'] === 'quota') {
                break;
            }
            if (in_array($result['status'], ['sent', 'already'], true)) {
                $sent[$url] = now()->toIso8601String();
                $result['status'] === 'sent' ? $done++ : $already++;
                if ($result['status'] === 'sent' && $done <= 15) {
                    $this->line('На переобход: '.self::short($url).' — '.$why.'.');
                }
            }
        }

        $left = count($candidates) - $done - $already;
        $this->line("Переобход: отправлено {$done}".($already ? ", уже стояли в очереди {$already}" : '')
            .($left > 0 ? ", осталось на следующие дни {$left}" : '').". Квота Яндекса: {$quota['daily']} в сутки.");

        return $sent;
    }

    /**
     * @param  list<string>  $before  коды серьёзных проблем с прошлого запуска
     * @return list<string>
     */
    private function diagnostics(array $before, bool $notify): array
    {
        $serious = array_values(array_filter($this->webmaster->problems(),
            fn (array $p): bool => in_array($p['severity'], ['FATAL', 'CRITICAL'], true)));
        $now = array_column($serious, 'code');

        foreach ($serious as $problem) {
            if (! in_array($problem['code'], $before, true) && $notify) {
                $this->notify('⚠️ <b>Яндекс Вебмастер: '.e(Webmaster::SEVERITY[$problem['severity']] ?? $problem['severity']).'</b> — '.e($problem['label']).'.');
            }
        }
        foreach (array_diff($before, $now) as $code) {
            if ($notify) {
                $this->notify('✅ <b>Яндекс снял проблему:</b> '.e(Webmaster::PROBLEMS[$code] ?? $code).'.');
            }
        }

        $this->line($serious === [] ? 'Серьёзных проблем в Вебмастере нет.'
            : 'Серьёзные проблемы в Вебмастере: '.implode('; ', array_column($serious, 'label')).'.');

        return $now;
    }

    // --------------------------------------------------------- помощники

    private function step(string $name, callable $work): void
    {
        try {
            $work();
        } catch (AnalyticsException $e) {
            $this->line($name.': '.$e->getMessage());
        } catch (Throwable $e) {
            Log::warning('SEO-автопилот: шаг не выполнен.', ['step' => $name, 'error' => $e->getMessage()]);
            $this->line($name.': не получилось — '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    private function line(string $text): void
    {
        $this->log[] = $text;
    }

    private function notify(string $html): void
    {
        try {
            $this->telegram->send($html, 'seo', 20.0);
        } catch (Throwable $e) {
            Log::warning('SEO-автопилот: не ушло сообщение в Telegram.', ['error' => $e->getMessage()]);
        }
    }

    /** Код ответа нашего сайта по адресу — без перехода по переадресации. */
    private static function status(string $url): ?int
    {
        $ours = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $target = $host === $ours ? $url : self::absolute((string) parse_url($url, PHP_URL_PATH));
        try {
            return Http::withHeaders(['User-Agent' => SiteHealth::UA])->withoutRedirecting()->timeout(8)->head($target)->status();
        } catch (Throwable) {
            return null;
        }
    }

    public static function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private static function short(string $url): string
    {
        return rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    }
}
