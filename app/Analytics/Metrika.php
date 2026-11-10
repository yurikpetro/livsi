<?php

namespace App\Analytics;

use Illuminate\Support\Facades\Cache;

/**
 * Отчёты Яндекс Метрики для раздела «Аналитика сайта».
 *
 * Каждый блок страницы — отдельный запрос: если Метрика отказала в одном
 * (например, мало данных), остальные всё равно показываются. Ответы
 * кешируются на 15 минут — Метрика ограничивает число запросов в сутки,
 * а цифры чаще и не меняются.
 */
class Metrika extends YandexApi
{
    public const BASE = 'https://api-metrika.yandex.net';

    private const TTL = 900;

    /** Модель атрибуции «последний значимый переход» — та же, что по умолчанию в отчётах Метрики. */
    private const ATTRIBUTION = 'lastsign';

    protected function service(): string
    {
        return 'Метрика';
    }

    protected function scopeHint(): string
    {
        return 'Проверьте, что в приложении на oauth.yandex.ru отмечен доступ metrika:read и что счётчик принадлежит тому Яндекс-аккаунту, который подключён (или у него есть гостевой доступ).';
    }

    /**
     * Счётчики, доступные подключённому аккаунту.
     *
     * @return list<array{id: int, name: string, site: string, owner: string, status: string}>
     */
    public function counters(): array
    {
        $data = $this->request('GET', self::BASE.'/management/v1/counters', ['per_page' => 100]);

        return array_values(array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'site' => (string) ($row['site2']['site'] ?? $row['site'] ?? ''),
            'owner' => (string) ($row['owner_login'] ?? ''),
            'status' => (string) ($row['code_status'] ?? ''),
        ], (array) ($data['counters'] ?? [])));
    }

    /** @return array{visits: float, users: float, pageviews: float, bounce: float, duration: float, depth: float, new: float} */
    public function totals(Period $period): array
    {
        $keys = ['visits', 'users', 'pageviews', 'bounce', 'duration', 'depth', 'new'];
        $data = $this->stat($period, [
            'metrics' => 'ym:s:visits,ym:s:users,ym:s:pageviews,ym:s:bounceRate,ym:s:avgVisitDurationSeconds,ym:s:pageDepth,ym:s:newUsers',
        ]);
        $totals = array_map('floatval', (array) ($data['totals'] ?? []));

        return array_combine($keys, array_pad(array_slice($totals, 0, count($keys)), count($keys), 0.0));
    }

    /** Визиты и посетители по дням, включая дни без визитов. @return list<array{date: string, visits: int, users: int}> */
    public function daily(Period $period): array
    {
        $data = $this->stat($period, [
            'dimensions' => 'ym:s:date',
            'metrics' => 'ym:s:visits,ym:s:users',
            'sort' => 'ym:s:date',
            'limit' => 400,
        ]);
        $byDate = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            $byDate[(string) ($row['dimensions'][0]['name'] ?? '')] = [(int) ($row['metrics'][0] ?? 0), (int) ($row['metrics'][1] ?? 0)];
        }

        return array_map(fn (string $date): array => [
            'date' => $date,
            'visits' => $byDate[$date][0] ?? 0,
            'users' => $byDate[$date][1] ?? 0,
        ], $period->dates());
    }

    /** Источники: поиск, прямые заходы, ссылки с сайтов, реклама… */
    public function sources(Period $period): array
    {
        return $this->breakdown($period, 'ym:s:<attribution>TrafficSource', 12);
    }

    /** Поисковые системы — только переходы из поиска. */
    public function searchEngines(Period $period): array
    {
        return $this->breakdown($period, 'ym:s:<attribution>SearchEngineRoot', 10, "ym:s:<attribution>TrafficSource=='organic'");
    }

    /** Ссылки с других сайтов и из соцсетей. */
    public function referrers(Period $period): array
    {
        return $this->breakdown($period, 'ym:s:<attribution>ReferalSource', 10, "ym:s:<attribution>TrafficSource=='referral'");
    }

    public function cities(Period $period): array
    {
        return $this->breakdown($period, 'ym:s:regionCity', 20);
    }

    public function devices(Period $period): array
    {
        return $this->breakdown($period, 'ym:s:deviceCategory', 5);
    }

    /** Рекламные метки: откуда по utm пришли люди. */
    public function campaigns(Period $period): array
    {
        $data = $this->stat($period, [
            'dimensions' => 'ym:s:<attribution>UTMSource,ym:s:<attribution>UTMCampaign',
            'metrics' => 'ym:s:visits,ym:s:users,ym:s:bounceRate',
            'filters' => 'ym:s:<attribution>UTMSource!n',
            'sort' => '-ym:s:visits',
            'limit' => 30,
        ]);

        return array_map(fn (array $row): array => [
            'source' => self::name($row, 0),
            'campaign' => self::name($row, 1),
            'visits' => (int) ($row['metrics'][0] ?? 0),
            'users' => (int) ($row['metrics'][1] ?? 0),
            'bounce' => (float) ($row['metrics'][2] ?? 0),
        ], (array) ($data['data'] ?? []));
    }

    /** Популярные страницы по просмотрам. @return list<array{path: string, title: string, views: int, users: int}> */
    public function pages(Period $period): array
    {
        $data = $this->stat($period, [
            'dimensions' => 'ym:pv:URLPath',
            'metrics' => 'ym:pv:pageviews,ym:pv:users',
            'sort' => '-ym:pv:pageviews',
            'limit' => 20,
        ]);

        return array_map(fn (array $row): array => [
            'path' => self::name($row, 0),
            'views' => (int) ($row['metrics'][0] ?? 0),
            'users' => (int) ($row['metrics'][1] ?? 0),
        ], (array) ($data['data'] ?? []));
    }

    /**
     * Последние визиты: когда, откуда, с чего начал, сколько смотрел.
     * Людей Метрика по API не выдаёт — это строки «визит в 11:42, Ставрополь,
     * из поиска Яндекса, смартфон, 4 страницы».
     *
     * @return list<array{time: string, city: string, source: string, device: string, page: string, visits: int, depth: float, duration: float}>
     */
    public function recentVisits(int $limit = 40): array
    {
        $data = $this->stat(Period::recent(), [
            'dimensions' => 'ym:s:dateTime,ym:s:regionCity,ym:s:<attribution>TrafficSource,ym:s:deviceCategory,ym:s:startURLPath',
            'metrics' => 'ym:s:visits,ym:s:pageDepth,ym:s:avgVisitDurationSeconds',
            'sort' => '-ym:s:dateTime',
            'limit' => $limit,
        ], ttl: 300);

        return array_map(fn (array $row): array => [
            'time' => self::name($row, 0),
            'city' => self::name($row, 1),
            'source' => self::name($row, 2),
            'device' => self::name($row, 3),
            'page' => self::name($row, 4),
            'visits' => (int) ($row['metrics'][0] ?? 0),
            'depth' => (float) ($row['metrics'][1] ?? 0),
            'duration' => (float) ($row['metrics'][2] ?? 0),
        ], (array) ($data['data'] ?? []));
    }

    /** Сбросить кеш отчётов — кнопка «Обновить». */
    public static function forget(): void
    {
        Cache::forever('analytics:metrika:generation', (string) microtime(true));
    }

    /** @return list<array{name: string, visits: int, users: int, bounce: float, share: float}> */
    private function breakdown(Period $period, string $dimension, int $limit, ?string $filter = null): array
    {
        $data = $this->stat($period, array_filter([
            'dimensions' => $dimension,
            'metrics' => 'ym:s:visits,ym:s:users,ym:s:bounceRate',
            'filters' => $filter,
            'sort' => '-ym:s:visits',
            'limit' => $limit,
        ]));
        $total = max(1, (int) ($data['totals'][0] ?? 0));

        return array_map(fn (array $row): array => [
            'name' => self::name($row, 0),
            'visits' => (int) ($row['metrics'][0] ?? 0),
            'users' => (int) ($row['metrics'][1] ?? 0),
            'bounce' => (float) ($row['metrics'][2] ?? 0),
            'share' => round(100 * (int) ($row['metrics'][0] ?? 0) / $total, 1),
        ], (array) ($data['data'] ?? []));
    }

    /** @param array<string, scalar> $params @return array<string, mixed> */
    private function stat(Period $period, array $params, int $ttl = self::TTL): array
    {
        $counter = AnalyticsSettings::counterId();
        if ($counter === null) {
            throw new AnalyticsException('Не выбран счётчик Метрики: «Аналитика сайта» → «Подключение».');
        }

        $query = [
            'ids' => $counter,
            'date1' => $period->date1(),
            'date2' => $period->date2(),
            'lang' => 'ru',
            'accuracy' => 'full',
            'attribution' => self::ATTRIBUTION,
        ] + $params;

        $key = 'analytics:metrika:'.Cache::get('analytics:metrika:generation', '0').':'.md5(self::query($query));

        return Cache::remember($key, $ttl, fn (): array => $this->request('GET', self::BASE.'/stat/v1/data', $query));
    }

    private static function name(array $row, int $index): string
    {
        $name = $row['dimensions'][$index]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'не определено';
    }
}
