<?php

namespace App\Support;

use App\Analytics\TrafficChannel;
use Illuminate\Http\Request;

/**
 * Метки рекламной кампании и адрес перехода — для атрибуции заявок и заказов.
 *
 * Метки приходят в адресе первого визита, а заявку человек оставляет позже
 * и уже на другой странице. Поэтому метки складываются в сессию при входе
 * и достаются в момент отправки формы — иначе колонка `utm` в заявках
 * всегда была бы пустой.
 */
class Utm
{
    public const SESSION_KEY = 'utm';

    /** Поддерживаем стандартный набор плюс идентификаторы клика Яндекса и Google. */
    public const KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'yclid',
        'gclid',
    ];

    /**
     * Откуда возвращаются, а не приходят: страницы оплаты и входа через
     * Яндекс или VK. Такой переход источником визита не считаем — иначе
     * второй заказ в той же сессии «пришёл бы» из банка.
     */
    public const IGNORED_REFERERS = ['yoomoney.ru', 'yookassa.ru', 'tochka.com', 'cloudpayments.ru', 'cloudkassir.ru', 'nspk.ru',
        'oauth.yandex.ru', 'passport.yandex.ru', 'id.yandex.ru', 'sso.yandex.ru', 'id.vk.com', 'oauth.vk.com', 'login.vk.com',
        'id.vk.ru', 'oauth.vk.ru'];

    /**
     * Запоминаем источник первого визита и больше не перезаписываем: иначе
     * переход по внутренней ссылке с параметром затрёт исходный источник.
     *
     * Без меток запоминаем адрес, с которого пришли (поиск Яндекса, соцсеть,
     * другой сайт), — по нему раздел «Аналитика сайта» делит заказы по
     * источникам. Метки рекламы такой запись перекрывают: они точнее.
     */
    public static function remember(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $stored = $request->session()->get(self::SESSION_KEY);
        if (is_array($stored) && ! self::refererOnly($stored)) {
            return;
        }

        $marks = [];

        foreach (self::KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $marks[$key] = Text::utf8(mb_substr($value, 0, 255));
            }
        }

        $referer = Text::utf8(mb_substr((string) $request->headers->get('referer'), 0, 255)) ?: null;

        if ($marks === []) {
            // Без меток — только первый внешний переход за сессию.
            $host = TrafficChannel::host((string) $referer);
            if ($stored !== null || $host === null || TrafficChannel::matches($host, self::IGNORED_REFERERS)) {
                return;
            }
        }

        $marks['referer'] = $referer;
        $marks['landing'] = self::landing($request);
        $marks['first_at'] = now()->toIso8601String();

        $request->session()->put(self::SESSION_KEY, array_filter($marks, fn ($v) => $v !== null));
    }

    /**
     * Страница входа без лишних параметров: в адресе бывает токен заказа
     * из письма, хранить его в источнике незачем.
     */
    private static function landing(Request $request): string
    {
        $query = array_intersect_key($request->query(), array_flip(self::KEYS));
        $url = $request->url().($query !== [] ? '?'.http_build_query($query) : '');

        return mb_substr($url, 0, 500);
    }

    /** @param array<string, mixed> $stored */
    private static function refererOnly(array $stored): bool
    {
        return array_intersect_key($stored, array_flip(self::KEYS)) === [];
    }

    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $request->session()->get(self::SESSION_KEY);
    }
}
