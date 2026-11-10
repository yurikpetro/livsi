<?php

namespace App\Analytics;

use Illuminate\Support\Facades\Cache;

/**
 * Яндекс Вебмастер: как сайт виден в поиске — показы, клики, позиции,
 * поисковые запросы, сколько страниц в поиске и код подтверждения прав.
 *
 * Вебмастер собирает статистику с задержкой в несколько дней, поэтому
 * последние дни периода бывают пустыми — это не ошибка.
 */
class Webmaster extends YandexApi
{
    public const BASE = 'https://api.webmaster.yandex.net/v4';

    private const TTL = 3600;

    public const INDICATORS = ['TOTAL_SHOWS', 'TOTAL_CLICKS', 'AVG_SHOW_POSITION', 'AVG_CLICK_POSITION'];

    public const SEVERITY = [
        'FATAL' => 'критично',
        'CRITICAL' => 'серьёзно',
        'POSSIBLE_PROBLEM' => 'стоит проверить',
        'RECOMMENDATION' => 'совет',
    ];

    /** Проблемы из «Диагностики сайта» Вебмастера — по-человечески. */
    public const PROBLEMS = [
        'CONNECT_FAILED' => 'Робот Яндекса не смог зайти на сайт (сервер не ответил или был перегружен)',
        'DISALLOWED_IN_ROBOTS' => 'Сайт закрыт от индексирования в robots.txt',
        'DNS_ERROR' => 'Ошибка DNS: робот не смог найти сервер сайта',
        'MAIN_PAGE_ERROR' => 'Главная страница отвечает ошибкой',
        'THREATS' => 'Яндекс нашёл угрозы безопасности или нарушения',
        'INSIGNIFICANT_CGI_PARAMETER' => 'Страницы с параметрами в адресе (?…) дублируют обычные страницы',
        'SLOW_AVG_RESPONSE_TIME' => 'Сайт долго отвечает роботу',
        'SSL_CERTIFICATE_ERROR' => 'Ошибка в SSL-сертификате (https)',
        'URL_ALERT_4XX' => 'Часть страниц отвечала роботу ошибкой 4xx (например, 404 «не найдено» или 401 «нужен вход»)',
        'URL_ALERT_5XX' => 'Часть страниц отвечала роботу ошибкой сервера 5xx',
        'DISALLOWED_URLS_ALERT' => 'Полезные страницы закрыты от индексирования',
        'DOCUMENTS_MISSING_DESCRIPTION' => 'На многих страницах нет описания (meta description)',
        'DOCUMENTS_MISSING_TITLE' => 'На многих страницах нет заголовка (title)',
        'DUPLICATE_CONTENT_ATTRS' => 'У некоторых страниц одинаковые заголовок и описание',
        'DUPLICATE_PAGES' => 'Некоторые страницы повторяют друг друга',
        'ERROR_IN_ROBOTS_TXT' => 'Ошибки в robots.txt',
        'ERRORS_IN_SITEMAPS' => 'Ошибки в карте сайта (Sitemap)',
        'FAVICON_ERROR' => 'Значок сайта (favicon) недоступен',
        'MAIN_MIRROR_IS_NOT_HTTPS' => 'Главное зеркало сайта — без https',
        'MAIN_PAGE_REDIRECTS' => 'Главная страница перенаправляет на другой сайт',
        'NO_METRIKA_COUNTER_BINDING' => 'К сайту не привязан счётчик Метрики',
        'NO_METRIKA_COUNTER_CRAWL_ENABLED' => 'Не включён обход страниц по счётчику Метрики',
        'NO_ROBOTS_TXT' => 'Нет файла robots.txt',
        'NO_SITEMAPS' => 'Робот не использует ни одной карты сайта (Sitemap)',
        'NO_SITEMAP_MODIFICATIONS' => 'Карта сайта (Sitemap) давно не обновлялась',
        'NON_WORKING_VIDEO' => 'Робот не смог проиндексировать видео на сайте',
        'SOFT_404' => 'Несуществующие страницы отвечают не ошибкой 404',
        'TOO_MANY_DOMAINS_ON_SEARCH' => 'В поиске найдены поддомены сайта',
        'VIDEOHOST_OFFER_FAILED' => 'Соглашение для показа видео отклонено',
        'VIDEOHOST_OFFER_IS_NEEDED' => 'Нет соглашения для показа видео',
        'VIDEOHOST_OFFER_NEED_PAPER' => 'Для видео нужно отдельное соглашение с Яндексом',
        'BIG_FAVICON_ABSENT' => 'Нет большого значка сайта (favicon 120×120) для выдачи',
        'FAVICON_PROBLEM' => 'Значок сайта (favicon) не найден',
        'NO_METRIKA_COUNTER' => 'Нет счётчика Метрики',
        'NO_REGIONS' => 'Не задан регион сайта (Вебмастер → «Представление в поиске» → «Региональность»)',
        'NOT_IN_SPRAV' => 'Сайт не привязан к карточке организации в Яндекс Бизнесе',
        'NOT_MOBILE_FRIENDLY' => 'Сайт неудобен на телефоне',
    ];

    /** Почему страница исключена из поиска. */
    public const EXCLUDED = [
        'NOTHING_FOUND' => 'Робот не знает о странице или она долго была недоступна',
        'HOST_ERROR' => 'Робот не смог соединиться с сервером',
        'REDIRECT_NOTSEARCHABLE' => 'Перенаправляет на страницу, которой нет в поиске',
        'HTTP_ERROR' => 'Страница отвечала ошибкой',
        'NOT_CANONICAL' => 'Неосновной адрес — в поиске другой (canonical)',
        'NOT_MAIN_MIRROR' => 'Неглавное зеркало сайта (например, www)',
        'PARSER_ERROR' => 'Робот не смог прочитать страницу',
        'ROBOTS_HOST_ERROR' => 'Сайт был закрыт в robots.txt',
        'ROBOTS_URL_ERROR' => 'Страница закрыта в robots.txt',
        'DUPLICATE' => 'Дубль другой страницы сайта',
        'LOW_QUALITY' => 'Исключена алгоритмом как малополезная',
        'CLEAN_PARAMS' => 'Исключена правилом Clean-param в robots.txt',
        'NO_INDEX' => 'Закрыта метатегом noindex',
        'OTHER' => 'У робота нет свежих данных о странице',
    ];

    protected function service(): string
    {
        return 'Вебмастер';
    }

    protected function scopeHint(): string
    {
        return 'Проверьте, что в приложении на oauth.yandex.ru отмечены доступы webmaster:hostinfo и webmaster:verify.';
    }

    /** Номер пользователя Вебмастера — запоминается после первого запроса. */
    public function userId(): string
    {
        $cached = AnalyticsSettings::option('webmaster_user_id');
        if (is_scalar($cached) && (string) $cached !== '') {
            return (string) $cached;
        }
        $id = (string) ($this->request('GET', self::BASE.'/user')['user_id'] ?? '');
        if ($id === '') {
            throw new AnalyticsException('Вебмастер не назвал номер пользователя.');
        }
        AnalyticsSettings::setOptions(['webmaster_user_id' => $id]);

        return $id;
    }

    /**
     * Сайт в Вебмастере — тот, что в APP_URL (с www или без).
     *
     * @throws AnalyticsException если сайта нет в этом аккаунте
     */
    public function hostId(): string
    {
        $cached = AnalyticsSettings::option('webmaster_host_id');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $ours = self::bare((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosts = (array) ($this->request('GET', self::BASE.'/user/'.$this->userId().'/hosts')['hosts'] ?? []);
        $found = collect($hosts)
            ->filter(fn (array $host): bool => self::bare((string) parse_url((string) ($host['ascii_host_url'] ?? ''), PHP_URL_HOST)) === $ours)
            // Сначала подтверждённый и с https — именно он главное зеркало.
            ->sortByDesc(fn (array $host): int => ((int) (bool) ($host['verified'] ?? false)) * 2 + (int) str_starts_with((string) ($host['host_id'] ?? ''), 'https'))
            ->first();

        if (! $found) {
            throw new AnalyticsException('В Вебмастере подключённого Яндекс-аккаунта нет сайта '.$ours.'. Подключите тот аккаунт, в котором сайт добавлен («Отключить» → «Подключить Яндекс»).');
        }
        AnalyticsSettings::setOptions(['webmaster_host_id' => (string) $found['host_id']]);

        return (string) $found['host_id'];
    }

    /** @return array{sqi: int, searchable: int, excluded: int, problems: array<string, int>} */
    public function summary(): array
    {
        $data = $this->cached('summary', fn (): array => $this->request('GET', $this->host().'/summary'));

        return [
            'sqi' => (int) ($data['sqi'] ?? 0),
            'searchable' => (int) ($data['searchable_pages_count'] ?? 0),
            'excluded' => (int) ($data['excluded_pages_count'] ?? 0),
            'problems' => array_map('intval', (array) ($data['site_problems'] ?? [])),
        ];
    }

    /** Показы и клики по дням; дни, которых у Вебмастера ещё нет, — null. @return list<array{date: string, shows: ?int, clicks: ?int}> */
    public function history(Period $period): array
    {
        $query = [
            'query_indicator' => ['TOTAL_SHOWS', 'TOTAL_CLICKS'],
            'device_type_indicator' => 'ALL',
            'date_from' => $period->date1(),
            'date_to' => $period->date2(),
        ];
        $data = $this->cached('history:'.self::query($query),
            fn (): array => $this->request('GET', $this->host().'/search-queries/all/history', $query));

        $series = [];
        foreach (['TOTAL_SHOWS' => 'shows', 'TOTAL_CLICKS' => 'clicks'] as $indicator => $name) {
            foreach ((array) ($data['indicators'][$indicator] ?? []) as $point) {
                $series[substr((string) ($point['date'] ?? ''), 0, 10)][$name] = (int) round((float) ($point['value'] ?? 0));
            }
        }

        return array_map(fn (string $date): array => [
            'date' => $date,
            'shows' => $series[$date]['shows'] ?? null,
            'clicks' => $series[$date]['clicks'] ?? null,
        ], $period->dates());
    }

    /**
     * Показы и клики по дням с поправкой на то, до какого дня у Яндекса
     * вообще есть статистика: после этого дня он отдаёт нули, хотя на деле
     * просто ещё не посчитал. Такие дни — null («данных нет»), а не 0.
     *
     * @return list<array{date: string, shows: ?int, clicks: ?int}>
     */
    public function historyUntil(Period $period, ?string $until): array
    {
        $rows = $this->history($period);
        if ($until === null) {
            return $rows;
        }

        return array_map(fn (array $row): array => $row['date'] > $until
            ? ['date' => $row['date'], 'shows' => null, 'clicks' => null]
            : $row, $rows);
    }

    /**
     * Запросы, по которым сайт показывался, — до 500 самых частых.
     *
     * Без периода Яндекс отдаёт последнюю неделю, за которую у него есть
     * данные. from/to — период, который Яндекс реально посчитал: он бывает
     * у́же запрошенного, если свежих данных ещё (или уже) нет.
     *
     * @return array{rows: list<array{query: string, shows: int, clicks: int, ctr: float, position: ?float, click_position: ?float}>, from: ?string, to: ?string}
     */
    public function popular(?Period $period = null, string $orderBy = 'TOTAL_SHOWS', int $limit = 100): array
    {
        $query = [
            'order_by' => $orderBy === 'TOTAL_CLICKS' ? 'TOTAL_CLICKS' : 'TOTAL_SHOWS',
            'query_indicator' => self::INDICATORS,
            'device_type_indicator' => 'ALL',
            'date_from' => $period?->date1(),
            'date_to' => $period?->date2(),
            'limit' => max(1, min(500, $limit)),
        ];
        $data = $this->cached('popular:'.self::query($query),
            fn (): array => $this->request('GET', $this->host().'/search-queries/popular', $query));

        $rows = array_map(function (array $row): array {
            $i = (array) ($row['indicators'] ?? []);
            $shows = (int) round((float) ($i['TOTAL_SHOWS'] ?? 0));
            $clicks = (int) round((float) ($i['TOTAL_CLICKS'] ?? 0));

            return [
                'query' => (string) ($row['query_text'] ?? ''),
                'shows' => $shows,
                'clicks' => $clicks,
                'ctr' => $shows > 0 ? round(100 * $clicks / $shows, 1) : 0.0,
                'position' => isset($i['AVG_SHOW_POSITION']) ? round((float) $i['AVG_SHOW_POSITION'], 1) : null,
                'click_position' => isset($i['AVG_CLICK_POSITION']) ? round((float) $i['AVG_CLICK_POSITION'], 1) : null,
            ];
        }, (array) ($data['queries'] ?? []));

        return [
            'rows' => $rows,
            'from' => self::day($data['date_from'] ?? null),
            'to' => self::day($data['date_to'] ?? null),
        ];
    }

    /**
     * До какого дня у Яндекса есть статистика запросов: конец последней
     * недели, которую он отдаёт без периода. null — не знаем.
     */
    public function dataUntil(): ?string
    {
        return $this->popular(null, limit: 1)['to'];
    }

    /**
     * Проблемы сайта, которые Вебмастер видит сейчас, — серьёзные первыми.
     *
     * @return list<array{code: string, severity: string, label: string, since: ?string}>
     */
    public function problems(): array
    {
        $data = $this->cached('diagnostics', fn (): array => $this->request('GET', $this->host().'/diagnostics'));
        $order = ['FATAL' => 0, 'CRITICAL' => 1, 'POSSIBLE_PROBLEM' => 2, 'RECOMMENDATION' => 3];
        $list = [];
        foreach ((array) ($data['problems'] ?? []) as $code => $problem) {
            if (($problem['state'] ?? '') !== 'PRESENT') {
                continue;
            }
            $list[] = [
                'code' => (string) $code,
                'severity' => (string) ($problem['severity'] ?? ''),
                'label' => self::PROBLEMS[$code] ?? (string) $code,
                'since' => self::day($problem['last_state_update'] ?? null),
            ];
        }
        usort($list, fn (array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return $list;
    }

    /**
     * Последние появления страниц в поиске и исключения из него — с причиной.
     *
     * @return list<array{url: string, title: string, date: ?string, removed: bool, reason: ?string}>
     */
    public function searchEvents(int $limit = 100): array
    {
        $query = ['offset' => 0, 'limit' => max(1, min(100, $limit))];
        $data = $this->cached('events:'.self::query($query),
            fn (): array => $this->request('GET', $this->host().'/search-urls/events/samples', $query));

        $rows = array_map(function (array $row): array {
            $status = (string) ($row['excluded_url_status'] ?? '');
            $reason = $status !== '' ? (self::EXCLUDED[$status] ?? $status) : null;
            if ($status === 'HTTP_ERROR' && isset($row['bad_http_status'])) {
                $reason .= ' (код '.(int) $row['bad_http_status'].')';
            }
            if (in_array($status, ['NOT_CANONICAL', 'REDIRECT_NOTSEARCHABLE', 'DUPLICATE'], true) && filled($row['target_url'] ?? null)) {
                $reason .= ' → '.$row['target_url'];
            }

            return [
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'date' => self::day($row['event_date'] ?? null),
                'removed' => ($row['event'] ?? '') === 'REMOVED_FROM_SEARCH',
                'reason' => ($row['event'] ?? '') === 'REMOVED_FROM_SEARCH' ? $reason : null,
            ];
        }, (array) ($data['samples'] ?? []));
        usort($rows, fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $rows;
    }

    /** @return array{uin: ?string, state: string, type: ?string} */
    public function verification(): array
    {
        $data = $this->request('GET', $this->host().'/verification');

        return [
            'uin' => isset($data['verification_uin']) ? (string) $data['verification_uin'] : null,
            'state' => (string) ($data['verification_state'] ?? ''),
            'type' => isset($data['verification_type']) ? (string) $data['verification_type'] : null,
        ];
    }

    /** Попросить Вебмастер проверить метатег на главной прямо сейчас. */
    public function requestMetaTagCheck(): array
    {
        $data = $this->request('POST', $this->host().'/verification', ['verification_type' => 'META_TAG']);

        return [
            'state' => (string) ($data['verification_state'] ?? ''),
            'type' => isset($data['verification_type']) ? (string) $data['verification_type'] : null,
        ];
    }

    public static function verificationLabel(?string $state, ?string $type = null): string
    {
        $how = match ($type) {
            'DNS' => 'записью DNS',
            'META_TAG' => 'метатегом на главной',
            'HTML_FILE' => 'HTML-файлом',
            'WHOIS' => 'через WHOIS',
            'DELEGATED' => 'делегированием',
            'PDD' => 'через Яндекс 360',
            default => null,
        };

        return match ($state) {
            'VERIFIED' => 'права подтверждены'.($how ? ' '.$how : ''),
            'IN_PROGRESS' => 'Яндекс проверяет права',
            'VERIFICATION_FAILED' => 'проверка не прошла — метатега нет на главной или код другой',
            'NOT_VERIFIED', 'NONE' => 'права не подтверждены',
            'INTERNAL_ERROR' => 'у Яндекса внутренняя ошибка, попробуйте позже',
            default => $state ?: 'неизвестно',
        };
    }

    public static function forget(): void
    {
        Cache::forever('analytics:webmaster:generation', (string) microtime(true));
    }

    private function host(): string
    {
        return self::BASE.'/user/'.$this->userId().'/hosts/'.$this->hostId();
    }

    /** @param callable(): array<string, mixed> $load @return array<string, mixed> */
    private function cached(string $name, callable $load): array
    {
        $key = 'analytics:webmaster:'.Cache::get('analytics:webmaster:generation', '0').':'.md5($name.'|'.AnalyticsSettings::option('webmaster_host_id'));

        return Cache::remember($key, self::TTL, $load);
    }

    /** «2026-10-06T00:00:00.000+03:00» или «2026-10-06» → «2026-10-06». */
    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    private static function bare(string $host): string
    {
        return preg_replace('/^www\./i', '', mb_strtolower($host));
    }
}
