<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Метки рекламной кампании для атрибуции заявок.
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
     * Запоминаем метки первого визита и больше не перезаписываем: иначе
     * переход по внутренней ссылке с параметром затрёт исходный источник.
     */
    public static function remember(Request $request): void
    {
        if (! $request->hasSession() || $request->session()->has(self::SESSION_KEY)) {
            return;
        }

        $marks = [];

        foreach (self::KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $marks[$key] = Text::utf8(mb_substr($value, 0, 255));
            }
        }

        if ($marks !== []) {
            $marks['referer']  = Text::utf8(mb_substr((string) $request->headers->get('referer'), 0, 255)) ?: null;
            $marks['landing']  = mb_substr($request->fullUrl(), 0, 500);
            $marks['first_at'] = now()->toIso8601String();

            $request->session()->put(self::SESSION_KEY, array_filter($marks, fn ($v) => $v !== null));
        }
    }

    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $request->session()->get(self::SESSION_KEY);
    }
}
