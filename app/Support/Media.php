<?php

namespace App\Support;

/**
 * Доступ к производным изображениям, которые собирает `npm run images`.
 *
 * Шаблоны обращаются только сюда, поэтому переезд обработки с Node на PHP
 * (когда в окружении включат gd или imagick и появится загрузка картинок
 * из админки) не потребует правок вёрстки — достаточно чтобы кто-то
 * продолжал писать манифест того же формата.
 *
 * Если производных нет — компонент отдаёт исходный файл как есть.
 */
final class Media
{
    public const FORMATS = ['avif', 'webp'];

    private static ?array $manifest = null;

    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = public_path('img/derived/manifest.json');

        if (! is_file($path)) {
            return self::$manifest = [];
        }

        return self::$manifest = json_decode((string) file_get_contents($path), true) ?: [];
    }

    public static function has(string $path): bool
    {
        return isset(self::manifest()[$path]);
    }

    /** `srcset` для формата: «img/derived/x-400.webp 400w, …». */
    public static function srcset(string $path, string $format): ?string
    {
        $variants = self::manifest()[$path][$format] ?? [];

        if ($variants === []) {
            return null;
        }

        return collect($variants)
            ->map(fn (array $v) => asset($v['src']) . ' ' . $v['width'] . 'w')
            ->implode(', ');
    }

    /**
     * Самый широкий JPEG как основной `src`: он подхватится браузером,
     * который не понимает ни AVIF, ни WebP.
     */
    public static function fallback(string $path): string
    {
        $variants = self::manifest()[$path]['jpg'] ?? [];

        return asset($variants === [] ? $path : end($variants)['src']);
    }

    /** Собственные размеры исходника — чтобы браузер не дёргал вёрстку. */
    public static function dimensions(string $path): ?array
    {
        $entry = self::manifest()[$path] ?? null;

        if (! $entry || ! isset($entry['width'], $entry['height'])) {
            return null;
        }

        return ['width' => $entry['width'], 'height' => $entry['height']];
    }

    /** Только для тестов: сбросить закэшированный манифест. */
    public static function flush(): void
    {
        self::$manifest = null;
    }
}
