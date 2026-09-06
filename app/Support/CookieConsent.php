<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Решение посетителя по файлам cookie.
 *
 * Отдельный класс, а не проверка куки по месту: когда появится аналитика,
 * она должна спрашивать разрешение здесь, и правило «без согласия не грузим»
 * будет в одном месте, а не размазано по шаблонам.
 *
 * Сама кука ставится браузером — не сервером: решение хранится локально
 * и на сервер не отправляется, потому что серверу оно пока не нужно.
 */
final class CookieConsent
{
    public const COOKIE = 'livsi_cookie_consent';

    public const ALL       = 'all';
    public const NECESSARY = 'necessary';

    /** Согласился ли посетитель на аналитические файлы. */
    public static function analyticsAllowed(Request $request): bool
    {
        return $request->cookie(self::COOKIE) === self::ALL;
    }

    /** Сделан ли выбор вообще: пока нет — показываем баннер. */
    public static function decided(Request $request): bool
    {
        return in_array($request->cookie(self::COOKIE), [self::ALL, self::NECESSARY], true);
    }
}
