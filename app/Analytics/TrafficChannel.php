<?php

namespace App\Analytics;

/**
 * Откуда пришёл покупатель — по меткам и адресу перехода, которые сайт
 * запомнил в начале визита (App\Support\Utm) и записал в заказ.
 *
 * Это наши собственные данные: считаются для всех покупателей, даже для
 * тех, кто отказался от аналитических cookie, и совпадают с деньгами
 * в заказах до рубля.
 */
final class TrafficChannel
{
    public const LABELS = [
        'ad_yandex' => 'Реклама в Яндексе (Директ)',
        'ad_google' => 'Реклама в Google',
        'ad' => 'Другая реклама',
        'search_yandex' => 'Поиск Яндекса',
        'search_google' => 'Поиск Google',
        'search_other' => 'Другие поисковики',
        'social' => 'Соцсети и мессенджеры',
        'marketplace' => 'Со страниц маркетплейсов',
        'email' => 'Письма и рассылки',
        'utm' => 'Ссылки с метками',
        'referral' => 'Ссылки с других сайтов',
        'direct' => 'Прямые заходы и без источника',
    ];

    private const PAID_MEDIUMS = ['cpc', 'ppc', 'cpm', 'cpa', 'cpv', 'paid', 'ads', 'ad', 'banner', 'display', 'target', 'targeting', 'retargeting', 'smm_paid'];

    private const SOCIAL = ['vk.com', 'vk.ru', 'm.vk.com', 't.me', 'telegram.org', 'web.telegram.org', 'ok.ru', 'instagram.com', 'l.instagram.com',
        'youtube.com', 'youtu.be', 'dzen.ru', 'zen.yandex.ru', 'facebook.com', 'l.facebook.com', 'pinterest.com', 'tiktok.com', 'whatsapp.com',
        'wa.me', 'rutube.ru', 'max.ru', 'twitter.com', 'x.com'];

    private const MARKETPLACES = ['wildberries.ru', 'wb.ru', 'ozon.ru', 'market.yandex.ru', 'megamarket.ru', 'avito.ru'];

    private const SEARCH_OTHER = ['bing.com', 'go.mail.ru', 'search.mail.ru', 'rambler.ru', 'duckduckgo.com', 'yahoo.com', 'sputnik.ru', 'nova.rambler.ru'];

    /**
     * Канал и подробность: «Поиск Яндекса», «Реклама в Яндексе · кампания».
     *
     * @param  array<string, mixed>|null  $utm
     * @return array{key: string, label: string, detail: ?string}
     */
    public static function of(?array $utm): array
    {
        $utm = (array) $utm;
        $source = mb_strtolower(trim((string) ($utm['utm_source'] ?? '')));
        $medium = mb_strtolower(trim((string) ($utm['utm_medium'] ?? '')));
        $campaign = trim((string) ($utm['utm_campaign'] ?? '')) ?: null;
        $host = self::host((string) ($utm['referer'] ?? ''));

        $key = match (true) {
            filled($utm['yclid'] ?? null),
            in_array($medium, self::PAID_MEDIUMS, true) && preg_match('/yandex|direct|ya\b|ya_/', $source) => 'ad_yandex',
            filled($utm['gclid'] ?? null),
            in_array($medium, self::PAID_MEDIUMS, true) && str_contains($source, 'google') => 'ad_google',
            in_array($medium, self::PAID_MEDIUMS, true) => 'ad',
            in_array($medium, ['email', 'e-mail', 'mail', 'newsletter', 'mailing', 'rassylka'], true) => 'email',
            $source !== '' => 'utm',
            $host === null => 'direct',
            str_starts_with($host, 'mail.') || $host === 'e.mail.ru' => 'email',
            self::matches($host, self::MARKETPLACES) => 'marketplace',
            self::matches($host, self::SOCIAL) => 'social',
            self::matches($host, ['ya.ru', 'yandex.ru', 'yandex.com', 'yandex.by', 'yandex.kz', 'yandex.uz', 'yandex.com.tr']) => 'search_yandex',
            (bool) preg_match('/(^|\.)google\.[a-z.]+$/', $host) => 'search_google',
            self::matches($host, self::SEARCH_OTHER) => 'search_other',
            default => 'referral',
        };

        $detail = match ($key) {
            'ad_yandex', 'ad_google', 'ad', 'email', 'utm' => trim(implode(' · ', array_filter([$source ?: null, $campaign])), ' ·') ?: null,
            'referral', 'social', 'marketplace', 'search_other' => $host,
            default => null,
        };

        return ['key' => $key, 'label' => self::LABELS[$key], 'detail' => $detail];
    }

    /** Хост перехода без www; свой сайт и пустой адрес — null. */
    public static function host(string $url): ?string
    {
        $host = mb_strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        if ($host === '') {
            return null;
        }
        $ours = (string) preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        return $host === $ours ? null : $host;
    }

    /** @param list<string> $domains */
    public static function matches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
