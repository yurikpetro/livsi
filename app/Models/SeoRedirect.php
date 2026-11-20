<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Переадресация 301 со старого адреса — её настраивает владелец
 * в «Аналитика сайта» → «Битые ссылки» / «Переадресации».
 */
class SeoRedirect extends Model
{
    public const CACHE = 'seo:redirects:map';

    protected $guarded = [];

    protected $casts = ['last_hit_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (SeoRedirect $redirect): void {
            $redirect->from_path = self::normalize((string) $redirect->from_path);
            $redirect->to_url = trim((string) $redirect->to_url);
        });
        static::saved(fn () => Cache::forget(self::CACHE));
        static::deleted(fn () => Cache::forget(self::CACHE));
    }

    /** Адрес для сравнения: без домена, параметров и концевого слэша, с одним слэшем в начале, маленькими буквами. */
    public static function normalize(string $path): string
    {
        $path = (string) (parse_url(trim($path), PHP_URL_PATH) ?? '');
        $path = '/'.trim(rawurldecode($path), '/');

        return mb_substr(mb_strtolower($path), 0, 300);
    }

    /**
     * Старый адрес теперь ведёт на новый (сменили адрес товара).
     *
     * Цепочки схлопываем сразу: всё, что вело на старый адрес, ведёт на новый —
     * одна переадресация вместо двух подряд. Переадресацию с нового адреса
     * (вернули прежний адрес) убираем, иначе страница вела бы сама на себя.
     */
    public static function follow(string $from, string $to): void
    {
        $fromPath = self::normalize($from);
        $toPath = self::normalize($to);
        if ($fromPath === $toPath || $fromPath === '/') {
            return;
        }
        self::query()->where('from_path', $toPath)->get()->each->delete();
        self::query()->get()
            ->filter(fn (self $redirect): bool => parse_url($redirect->to_url, PHP_URL_HOST) === null && self::normalize($redirect->to_url) === $fromPath)
            ->each(fn (self $redirect) => $redirect->update(['to_url' => $to]));
        self::query()->updateOrCreate(['from_path' => $fromPath], ['to_url' => $to]);
    }

    /** Все переадресации одним словарём «откуда → куда»: проверяются на каждом запросе. @return array<string, string> */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE,
            fn (): array => self::query()->pluck('to_url', 'from_path')->all());
    }
}
