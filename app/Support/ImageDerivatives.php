<?php

namespace App\Support;

use GdImage;
use Throwable;

/**
 * Уменьшенные копии картинки, загруженной из админки (баннеры): WebP и JPEG
 * в нескольких ширинах — тот же формат, что собирает `npm run images`, только
 * в отдельный файл img/derived/uploads.json, чтобы не мешать сборке.
 *
 * Нужен PHP с GD. Без него сайт отдаёт исходник как есть — страница не
 * ломается, только весит больше.
 */
final class ImageDerivatives
{
    public const UPLOADS = 'img/derived/uploads.json';

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp') && function_exists('imagejpeg');
    }

    /**
     * @param  list<int>  $widths
     */
    public static function make(string $path, array $widths = [800, 1200, 1600, 2560]): bool
    {
        $source = public_path($path);
        if (! self::available() || ! is_file($source) || ! ($size = @getimagesize($source))) {
            return false;
        }
        [$width, $height] = $size;

        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));
            if (! $image instanceof GdImage) {
                return false;
            }
            $entry = ['width' => $width, 'height' => $height, 'webp' => [], 'jpg' => []];
            $base = preg_replace('/\.[^.\/]+$/', '', (string) preg_replace('#^img/#', '', $path));
            foreach (array_values(array_unique(array_map(fn (int $step): int => min($step, $width), $widths))) as $step) {
                $resized = $step < $width ? imagescale($image, $step, (int) round($height * $step / $width), IMG_BICUBIC) : $image;
                if (! $resized instanceof GdImage) {
                    continue;
                }
                $webp = 'img/derived/'.$base.'-'.$step.'.webp';
                $jpg = 'img/derived/'.$base.'-'.$step.'.jpg';
                @mkdir(dirname(public_path($webp)), 0755, true);
                imagewebp($resized, public_path($webp), 78);
                imagejpeg(self::onWhite($resized), public_path($jpg), 82);
                $entry['webp'][] = ['width' => $step, 'src' => $webp];
                $entry['jpg'][] = ['width' => $step, 'src' => $jpg];
            }
        } catch (Throwable) {
            return false;
        }

        self::remember($path, $entry);
        Media::flush();

        return true;
    }

    /** Убрать запись о копиях (когда картинку заменили или убрали). */
    public static function forget(string $path): void
    {
        self::remember($path, null);
        Media::flush();
    }

    /** JPEG не умеет прозрачность: прозрачное поле PNG станет белым, а не чёрным. */
    private static function onWhite(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    /** @param array<string, mixed>|null $entry */
    private static function remember(string $path, ?array $entry): void
    {
        $file = public_path(self::UPLOADS);
        @mkdir(dirname($file), 0755, true);
        $handle = fopen($file, 'c+');
        if (! $handle) {
            return;
        }
        flock($handle, LOCK_EX);
        $manifest = json_decode((string) stream_get_contents($handle), true) ?: [];
        if ($entry === null) {
            unset($manifest[$path]);
        } else {
            $manifest[$path] = $entry;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
