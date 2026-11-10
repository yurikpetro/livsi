<?php

namespace App\Seo;

use App\Analytics\AnalyticsSettings;
use App\Analytics\Metrika;
use App\Analytics\Period;
use App\Analytics\SalesBySource;
use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use App\Models\MissingPage;
use App\Models\SeoRedirect;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Сводки о поиске: короткая — в Telegram по понедельникам, полная —
 * файлом «для Claude» (кнопка в админке): по нему готовятся следующие
 * улучшения сайта. В файле нет ни ключей, ни данных покупателей —
 * только цифры, адреса страниц и поисковые запросы.
 */
final class SeoReport
{
    public function __construct(
        private readonly YandexOAuth $oauth,
        private readonly Webmaster $webmaster,
        private readonly Metrika $metrika,
    ) {}

    /** Недельная сводка в Telegram (HTML). */
    public function weekly(): string
    {
        $lines = ['📈 <b>livsi.shop — поиск и сайт за неделю</b>'];
        $state = Autopilot::state();
        $health = (array) ($state['health'] ?? []);
        if ($health !== []) {
            $lines[] = ($health['ok'] ?? false) ? '✅ Сайт открыт для поисковиков.' : '🚨 Сайт закрыт для поисковиков — см. «Поиск Яндекса» в админке.';
        }

        if ($this->oauth->connected()) {
            $this->attempt(function () use (&$lines): void {
                $s = $this->webmaster->summary();
                $p = $s['problems'];
                $lines[] = "В поиске Яндекса страниц: <b>{$s['searchable']}</b>, исключено: {$s['excluded']}, ИКС: {$s['sqi']}."
                    .(($p['FATAL'] ?? 0) + ($p['CRITICAL'] ?? 0) > 0 ? ' ⚠️ Есть серьёзные проблемы.' : '');
            });
            $this->attempt(function () use (&$lines): void {
                $q = $this->webmaster->popular(null, limit: 5);
                if ($q['rows'] === []) {
                    $lines[] = 'Запросов, по которым показывали сайт, у Яндекса пока нет.';

                    return;
                }
                $shows = array_sum(array_column($q['rows'], 'shows'));
                $clicks = array_sum(array_column($q['rows'], 'clicks'));
                $lines[] = 'Поиск, '.self::range($q['from'], $q['to']).": показы (топ-5) <b>{$shows}</b>, клики <b>{$clicks}</b>.";
                foreach ($q['rows'] as $row) {
                    $lines[] = '• '.e($row['query'])." — {$row['shows']} пок., {$row['clicks']} кл."
                        .($row['position'] !== null ? ', позиция '.str_replace('.', ',', (string) $row['position']) : '');
                }
            });
            if (AnalyticsSettings::counterId()) {
                $this->attempt(function () use (&$lines): void {
                    $t = $this->metrika->totals(Period::make('7d'));
                    $lines[] = 'Сайт за 7 дней (Метрика, только с согласием на cookie): людей <b>'.(int) $t['users'].'</b>, визитов '.(int) $t['visits'].'.';
                    $sources = array_slice($this->metrika->sources(Period::make('7d')), 0, 4);
                    if ($sources !== []) {
                        $lines[] = 'Откуда: '.implode(', ', array_map(fn ($r) => e($r['name']).' '.$r['visits'], $sources)).'.';
                    }
                });
            }
        } else {
            $lines[] = 'Яндекс к админке не подключён — данных поиска нет.';
        }

        $sales = SalesBySource::report(Period::make('7d'));
        $lines[] = 'Заказы за 7 дней: '.$sales['totals']['orders'].', оплачено '.$sales['totals']['paid'].' на '.Money::rub($sales['totals']['revenue']).'.';
        foreach (array_slice($sales['channels'], 0, 4) as $row) {
            $lines[] = '• '.e($row['label']).": {$row['orders']} зак., ".Money::rub($row['revenue']);
        }

        $missing = MissingPage::query()->where('ignored', false)->where('last_seen_at', '>=', now()->subDays(7))
            ->whereNotIn('path', SeoRedirect::query()->pluck('from_path'))->count();
        if ($missing > 0) {
            $lines[] = "🔗 Битых ссылок за неделю: {$missing} — «Аналитика сайта» → «Битые ссылки».";
        }

        $log = (array) ($state['log'] ?? []);
        $recrawl = array_values(array_filter($log, fn (string $l): bool => str_starts_with($l, 'Переобход: отправлено')));
        if ($recrawl !== []) {
            $lines[] = 'Автопилот: '.mb_strtolower(mb_substr($recrawl[0], 0, 1)).mb_substr($recrawl[0], 1);
        }

        return implode("\n", $lines);
    }

    /** Полный отчёт в Markdown — файлом. */
    public function full(): string
    {
        $md = ['# livsi.shop — SEO-отчёт', '', 'Сформирован: '.now(Period::TZ)->format('d.m.Y H:i').' (МСК). В отчёте нет ключей и данных покупателей.', ''];

        $state = Autopilot::state();
        $health = (array) ($state['health'] ?? SiteHealth::check());
        $md[] = '## Сайт глазами робота';
        $md[] = ($health['ok'] ?? false) ? 'Открыт для поисковиков.' : '**Закрыт или с ошибками:**';
        foreach ((array) ($health['problems'] ?? []) as $p) {
            $md[] = '- '.$p;
        }
        foreach ((array) ($health['checked'] ?? []) as $c) {
            $md[] = '- '.$c;
        }
        $md[] = '';

        $md[] = '## Автопилот';
        $md[] = 'Последний запуск: '.(isset($state['last_run_at']) ? Carbon::parse($state['last_run_at'])->timezone(Period::TZ)->format('d.m.Y H:i') : 'ещё не было');
        foreach ((array) ($state['log'] ?? []) as $l) {
            $md[] = '- '.$l;
        }
        $md[] = '';

        if ($this->oauth->connected()) {
            $this->attempt(function () use (&$md): void {
                $s = $this->webmaster->summary();
                $md[] = '## Вебмастер';
                $md[] = "Страниц в поиске: {$s['searchable']}, исключено: {$s['excluded']}, ИКС: {$s['sqi']}.";
                $md[] = '';
            });
            $this->attempt(function () use (&$md): void {
                $md[] = '### Проблемы';
                foreach ($this->webmaster->problems() as $p) {
                    $md[] = '- '.(Webmaster::SEVERITY[$p['severity']] ?? $p['severity']).': '.$p['label'].' (с '.($p['since'] ?? '—').')';
                }
                $md[] = '';
            });
            $this->attempt(function () use (&$md): void {
                $q = $this->webmaster->popular(null, limit: 100);
                $md[] = '### Запросы ('.self::range($q['from'], $q['to']).')';
                $md[] = '| Запрос | Показы | Клики | Позиция |';
                $md[] = '|---|---:|---:|---:|';
                foreach ($q['rows'] as $r) {
                    $md[] = '| '.str_replace('|', '/', $r['query'])." | {$r['shows']} | {$r['clicks']} | ".($r['position'] ?? '—').' |';
                }
                $md[] = '';
            });
            $this->attempt(function () use (&$md): void {
                $md[] = '### Страницы в поиске (до 100)';
                foreach ($this->webmaster->inSearch(100) as $row) {
                    $md[] = '- '.rawurldecode($row['url']).($row['title'] !== '' ? ' — '.$row['title'] : '');
                }
                $md[] = '';
            });
            $this->attempt(function () use (&$md): void {
                $md[] = '### Появились и исключены';
                foreach (array_slice($this->webmaster->searchEvents(), 0, 60) as $e) {
                    $md[] = '- '.($e['date'] ?? '—').' '.($e['removed'] ? 'исключена' : 'в поиске').': '.rawurldecode($e['url']).($e['reason'] ? ' — '.$e['reason'] : '');
                }
                $md[] = '';
            });
            if (AnalyticsSettings::counterId()) {
                $this->attempt(function () use (&$md): void {
                    $p = Period::make('30d');
                    $t = $this->metrika->totals($p);
                    $md[] = '## Метрика, 30 дней (только с согласием на cookie)';
                    $md[] = 'Люди: '.(int) $t['users'].', визиты: '.(int) $t['visits'].', просмотры: '.(int) $t['pageviews'].', отказы: '.round($t['bounce'], 1).'%.';
                    $md[] = '';
                    foreach (['Источники' => $this->metrika->sources($p), 'Поисковики' => $this->metrika->searchEngines($p), 'Города' => array_slice($this->metrika->cities($p), 0, 10)] as $title => $rows) {
                        $md[] = '### '.$title;
                        foreach ($rows as $r) {
                            $md[] = "- {$r['name']}: {$r['visits']} виз. ({$r['share']}%), отказы ".round($r['bounce'], 1).'%';
                        }
                        $md[] = '';
                    }
                    $md[] = '### Популярные страницы';
                    foreach ($this->metrika->pages($p) as $r) {
                        $md[] = '- '.rawurldecode($r['path']).": {$r['views']} просм.";
                    }
                    $md[] = '';
                });
            }
        }

        $sales = SalesBySource::report(Period::make('30d'));
        $md[] = '## Заказы по источникам, 30 дней';
        $md[] = 'Всего: '.$sales['totals']['orders'].', оплачено: '.$sales['totals']['paid'].', выручка: '.Money::rub($sales['totals']['revenue']).'.';
        foreach ($sales['channels'] as $row) {
            $md[] = "- {$row['label']}: {$row['orders']} зак., оплачено {$row['paid']}, ".Money::rub($row['revenue']);
        }
        $md[] = '';

        $md[] = '## Битые ссылки (404), самые частые';
        $redirects = SeoRedirect::query()->pluck('to_url', 'from_path')->all();
        foreach (MissingPage::query()->where('ignored', false)->orderByDesc('hits')->orderByDesc('bot_hits')->limit(60)->get() as $m) {
            $md[] = '- '.$m->path." — людей {$m->hits}, роботов {$m->bot_hits}"
                .(isset($redirects[$m->path]) ? ' → '.$redirects[$m->path] : ', не перенаправлена (подсказка: '.LegacyMatcher::suggest($m->path).')')
                .($m->last_referer ? ', пришли с '.$m->last_referer : '');
        }
        $md[] = '';
        $md[] = '## Переадресации, заданные в админке';
        foreach (SeoRedirect::query()->orderBy('from_path')->get() as $r) {
            $md[] = "- {$r->from_path} → {$r->to_url} ({$r->hits} перех.)";
        }

        return implode("\n", $md)."\n";
    }

    private function attempt(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Нет данных одного блока — остальное всё равно в отчёте.
        }
    }

    private static function range(?string $from, ?string $to): string
    {
        $f = fn (?string $d) => $d ? Carbon::parse($d)->format('d.m') : '—';

        return $f($from).'–'.$f($to);
    }
}
