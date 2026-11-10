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
     * Запросы, по которым сайт показывался, — до 500 самых частых.
     *
     * @return list<array{query: string, shows: int, clicks: int, ctr: float, position: ?float, click_position: ?float}>
     */
    public function popular(Period $period, string $orderBy = 'TOTAL_SHOWS', int $limit = 100): array
    {
        $query = [
            'order_by' => $orderBy === 'TOTAL_CLICKS' ? 'TOTAL_CLICKS' : 'TOTAL_SHOWS',
            'query_indicator' => self::INDICATORS,
            'device_type_indicator' => 'ALL',
            'date_from' => $period->date1(),
            'date_to' => $period->date2(),
            'limit' => max(1, min(500, $limit)),
        ];
        $data = $this->cached('popular:'.self::query($query),
            fn (): array => $this->request('GET', $this->host().'/search-queries/popular', $query));

        return array_map(function (array $row): array {
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

    private static function bare(string $host): string
    {
        return preg_replace('/^www\./i', '', mb_strtolower($host));
    }
}
