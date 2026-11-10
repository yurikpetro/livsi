<?php

namespace App\Analytics;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Настройки раздела «Аналитика сайта»: счётчик Метрики, приложение Яндекса
 * и выданный им доступ.
 *
 * Секреты (Client secret приложения и токены доступа) лежат одной записью,
 * зашифрованной ключом приложения, как ключи оплаты. В форму они не
 * возвращаются — только «задан / не задан».
 */
final class AnalyticsSettings
{
    private const OPTIONS = 'analytics_options';

    private const CREDENTIALS = 'analytics_credentials';

    /** Код подтверждения Вебмастера — отдельной записью: его выводит шапка каждой страницы. */
    public const VERIFICATION = 'yandex_verification';

    // ------------------------------------------------------------- параметры

    public static function option(string $key, mixed $default = null): mixed
    {
        $value = ((array) Setting::get(self::OPTIONS, []))[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** @param array<string, mixed> $values */
    public static function setOptions(array $values): void
    {
        Setting::put(self::OPTIONS, array_merge((array) Setting::get(self::OPTIONS, []), $values));
    }

    /** Номер счётчика Метрики — только цифры. */
    public static function counterId(): ?int
    {
        $value = preg_replace('/\D/', '', (string) self::option('metrika_counter_id', ''));

        return $value !== '' && strlen($value) <= 12 ? (int) $value : null;
    }

    /** Вебвизор (запись посещений) — по умолчанию включён. */
    public static function webvisor(): bool
    {
        return (bool) self::option('metrika_webvisor', true);
    }

    public static function clientId(): ?string
    {
        $value = trim((string) self::option('oauth_client_id', ''));

        return $value !== '' ? $value : null;
    }

    // ---------------------------------------------------------------- секреты

    public static function secret(string $key): ?string
    {
        $value = self::secrets()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Записать секреты; пустое значение — оставить как было. @param array<string, ?string> $values */
    public static function setSecrets(array $values): void
    {
        $all = self::secrets();
        foreach ($values as $key => $value) {
            $value = preg_replace('/\s+/', '', (string) $value);
            if ($value !== '') {
                $all[$key] = $value;
            }
        }
        self::store($all);
    }

    /** @param list<string> $keys */
    public static function forgetSecrets(array $keys): void
    {
        self::store(array_diff_key(self::secrets(), array_flip($keys)));
    }

    /** Все секреты — чтобы вычистить их из текста ошибок. @return list<string> */
    public static function allSecrets(): array
    {
        return array_values(array_filter(self::secrets(), fn ($value) => is_string($value) && $value !== ''));
    }

    /** @param array<string, string> $all */
    private static function store(array $all): void
    {
        Setting::put(self::CREDENTIALS, Crypt::encryptString(json_encode($all, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, string> */
    private static function secrets(): array
    {
        $raw = Setting::get(self::CREDENTIALS);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------- код Яндекс Вебмастера

    public static function verificationCode(): ?string
    {
        $value = (string) Setting::get(self::VERIFICATION, '');

        return $value !== '' ? $value : null;
    }

    public static function setVerificationCode(?string $code): void
    {
        Setting::put(self::VERIFICATION, self::normalizeVerification((string) $code));
    }

    /**
     * Из того, что вставили, — только сам код.
     *
     * В Вебмастере код показан строкой `<meta name="yandex-verification"
     * content="…" />`, и копируют её целиком. Такую строку разбираем сами,
     * а не заставляем человека выкусывать середину. Всё, кроме букв, цифр,
     * дефиса и подчёркивания, отбрасываем: значение выводится в шапку
     * каждой страницы.
     */
    public static function normalizeVerification(string $input): string
    {
        $input = trim($input);
        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $input, $match)) {
            $input = $match[1];
        }

        return mb_substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $input), 0, 64);
    }
}
