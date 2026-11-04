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

        // uploads.json пишет сайт сам, когда в админке загружают картинку
        // (баннеры); manifest.json — сборка `npm run images`. Если файл есть
        // в обоих, верим сборке.
        $manifest = [];
        foreach (['img/derived/uploads.json', 'img/derived/manifest.json'] as $file) {
            $path = public_path($file);
            if (is_file($path)) {
                $manifest = array_merge($manifest, json_decode((string) file_get_contents($path), true) ?: []);
            }
        }

        return self::$manifest = $manifest;
    }

    public static function has(string $path): bool
    {
        return isset(self::manifest()[$path]);
    }

    /**
     * Есть ли вообще картинка — производная или хотя бы исходник.
     *
     * `has()` отвечает только за манифест, поэтому для «показывать блок
     * или нет» он не годится: файл может лежать в `public`, а `npm run images`
     * ещё не прогоняться.
     */
    public static function available(string $path): bool
    {
        return self::has($path) || is_file(public_path($path));
    }

    /** `srcset` для формата: «img/derived/x-400.webp 400w, …». */
    public static function srcset(string $path, string $format): ?string
    {
        $variants = self::manifest()[$path][$format] ?? [];

        if ($variants === []) {
            return null;
        }

        return collect($variants)
            ->map(fn (array $v) => asset($v['src']).' '.$v['width'].'w')
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

    /**
     * Пропорции снимка для CSS aspect-ratio («1600 / 2000»): по манифесту,
     * а если производных нет — по самому файлу. Нет файла — null.
     */
    public static function ratio(string $path): ?string
    {
        $dimensions = self::dimensions($path);
        if (! $dimensions && is_file($file = public_path($path)) && ($size = @getimagesize($file))) {
            $dimensions = ['width' => $size[0], 'height' => $size[1]];
        }

        return $dimensions && $dimensions['width'] > 0 && $dimensions['height'] > 0
            ? $dimensions['width'].' / '.$dimensions['height']
            : null;
    }

    /** Только для тестов: сбросить закэшированный манифест. */
    public static function flush(): void
    {
        self::$manifest = null;
    }
}
