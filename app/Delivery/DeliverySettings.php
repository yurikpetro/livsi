<?php

namespace App\Delivery;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Настройки прямых служб, которые владелец меняет в админке
 * («Интеграции доставки»): режим, ключи, пункт сдачи посылок.
 *
 * Порядок такой: что задано в админке — то и действует; не задано —
 * берётся из .env, как было до этой страницы. Поэтому установка ничего
 * не меняет, пока в админке не нажали «сохранить».
 *
 * Ключи хранятся одной записью, зашифрованной ключом приложения (APP_KEY).
 * В форму они обратно не отдаются — только «задан / не задан».
 */
final class DeliverySettings
{
    /** Поля ключей каждой службы и откуда они брались до админки. */
    public const CREDENTIALS = [
        'cdek' => ['client_id' => 'livsi.cdek.client_id', 'client_secret' => 'livsi.cdek.client_secret'],
        'yataxi' => ['token' => 'livsi.yandex_delivery.token'],
        'ozondel' => ['client_id' => 'livsi.ozon.client_id', 'client_secret' => 'livsi.ozon.client_secret'],
    ];

    public static function driver(string $provider): string
    {
        $stored = (array) Setting::get('delivery_drivers', []);
        $value = $stored[$provider] ?? config('livsi.delivery.drivers.'.$provider, 'apiship');

        return $value === 'direct' ? 'direct' : 'apiship';
    }

    public static function setDriver(string $provider, string $mode): void
    {
        $stored = (array) Setting::get('delivery_drivers', []);
        $stored[$provider] = $mode === 'direct' ? 'direct' : 'apiship';
        Setting::put('delivery_drivers', $stored);
        Cache::forget('delivery-points-ready:'.$provider);
    }

    public static function credential(string $provider, string $key): ?string
    {
        $value = self::stored()[$provider][$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $config = self::CREDENTIALS[$provider][$key] ?? null;
        $fallback = $config ? config($config) : null;

        return is_scalar($fallback) && (string) $fallback !== '' ? (string) $fallback : null;
    }

    /** Откуда взят ключ: admin, env или null — для подсказки в админке. */
    public static function credentialSource(string $provider, string $key): ?string
    {
        if (is_string(self::stored()[$provider][$key] ?? null) && self::stored()[$provider][$key] !== '') {
            return 'admin';
        }

        return self::credential($provider, $key) !== null ? 'env' : null;
    }

    /** Записать ключи; пустое значение — оставить как было. @param array<string, ?string> $values */
    public static function setCredentials(string $provider, array $values): void
    {
        $all = self::stored();
        foreach (self::CREDENTIALS[$provider] ?? [] as $key => $_) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                $all[$provider][$key] = $value;
            }
        }
        Setting::put('delivery_credentials', Crypt::encryptString(json_encode($all, JSON_THROW_ON_ERROR)));
    }

    public static function origin(string $provider): ?string
    {
        $value = ((array) Setting::get('delivery_direct_origin', []))[$provider] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public static function setOrigin(string $provider, ?string $id, ?string $address = null): void
    {
        $origins = (array) Setting::get('delivery_direct_origin', []);
        $origins[$provider] = $id;
        Setting::put('delivery_direct_origin', $origins);
        $labels = (array) Setting::get('delivery_direct_origin_labels', []);
        $labels[$provider] = $address;
        Setting::put('delivery_direct_origin_labels', $labels);
    }

    public static function originLabel(string $provider): ?string
    {
        $value = ((array) Setting::get('delivery_direct_origin_labels', []))[$provider] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Итог последней загрузки пунктов выдачи. @return array<string, mixed>|null */
    public static function lastSync(string $provider): ?array
    {
        $value = ((array) Setting::get('delivery_points_sync', []))[$provider] ?? null;

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $result */
    public static function recordSync(string $provider, array $result): void
    {
        $all = (array) Setting::get('delivery_points_sync', []);
        $all[$provider] = ['at' => now()->toIso8601String()] + $result;
        Setting::put('delivery_points_sync', $all);
        self::setRunning($provider, false);
    }

    /** Загрузка пунктов идёт сейчас: отметка ставится в начале и снимается итогом. */
    public static function setRunning(string $provider, bool $running): void
    {
        $all = (array) Setting::get('delivery_points_running', []);
        if ($running) {
            $all[$provider] = now()->toIso8601String();
        } else {
            unset($all[$provider]);
        }
        Setting::put('delivery_points_running', $all);
    }

    /** Когда началась идущая загрузка; null — не идёт (или зависла больше 3 часов назад). */
    public static function runningSince(string $provider): ?Carbon
    {
        $at = ((array) Setting::get('delivery_points_running', []))[$provider] ?? null;
        $since = is_string($at) ? Carbon::parse($at) : null;

        return $since && $since->gt(now()->subHours(3)) ? $since : null;
    }

    public static function requestSync(string $provider): void
    {
        $all = (array) Setting::get('delivery_points_requested', []);
        $all[$provider] = now()->toIso8601String();
        Setting::put('delivery_points_requested', $all);
    }

    public static function syncRequested(string $provider): bool
    {
        return isset(((array) Setting::get('delivery_points_requested', []))[$provider]);
    }

    /** Забрать заявки на загрузку пунктов (и снять их). @return list<string> */
    public static function takeSyncRequests(): array
    {
        $all = (array) Setting::get('delivery_points_requested', []);
        if ($all === []) {
            return [];
        }
        Setting::put('delivery_points_requested', []);

        return array_values(array_intersect(DeliveryGateway::PROVIDERS, array_keys($all)));
    }

    /** @return array<string, array<string, string>> */
    private static function stored(): array
    {
        $raw = Setting::get('delivery_credentials');
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
}
