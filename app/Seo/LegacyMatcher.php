<?php

namespace App\Seo;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Куда вести старый адрес: на тот же товар (его можно узнать по адресу),
 * на похожий товар или в каталог.
 *
 * Им пользуются и переадресация старых адресов магазина (301 «на лету»),
 * и подсказка в «Битых ссылках».
 */
final class LegacyMatcher
{
    /** Начала адресов старого магазина. */
    public const LEGACY = '#^(magazin|shop|product|product-category|product-tag|tovar|tovary|katalog|catalog|category|tag)(/|$)#';

    /** Старые карты сайта: WordPress, Yoast, прежние генераторы. Отвечают содержимым новой — /sitemap.xml. */
    public const OLD_SITEMAP = '#^((wp-)?sitemap[\w.-]*\.xml(\.gz)?|sitemap_index\.xml|[\w-]+-sitemap\d*\.xml)$#i';

    /** Слова, по которым товары не различить. */
    private const COMMON = ['dlya', 'livsi', 'professional', 'premium', 'magazin', 'category', 'product', 'tovar'];

    /** Тот же товар по последнему узнаваемому кусочку адреса. */
    public static function product(string $path): ?Product
    {
        foreach (array_reverse(self::segments($path)) as $segment) {
            $product = Product::active()->where('slug', Str::slug($segment))->first();
            if ($product) {
                return $product;
            }
        }

        return null;
    }

    /** Похожий товар: не меньше двух значимых слов из старого адреса в новом. */
    public static function similar(string $path): ?Product
    {
        $segments = self::segments($path);
        $words = array_values(array_diff(array_filter(explode('-', Str::slug((string) end($segments))),
            fn (string $word): bool => mb_strlen($word) >= 4), self::COMMON));
        if (count($words) < 2) {
            return null;
        }

        $best = null;
        $score = 1;
        foreach (Product::active()->get(['id', 'slug']) as $product) {
            $hits = count(array_intersect($words, explode('-', $product->slug)));
            if ($hits > $score) {
                [$best, $score] = [$product, $hits];
            }
        }

        return $best;
    }

    public static function isLegacy(string $path): bool
    {
        return (bool) preg_match(self::LEGACY, self::clean($path));
    }

    /** Лучшее предложение для «Битых ссылок»: товар, похожий товар или каталог. */
    public static function suggest(string $path): string
    {
        if (preg_match(self::OLD_SITEMAP, self::clean($path))) {
            return route('sitemap', absolute: false);
        }
        $product = self::product($path) ?? self::similar($path);

        return $product ? route('catalog.show', $product, false) : route('catalog.index', [], false);
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', mb_strtolower(self::clean($path)))));
    }

    private static function clean(string $path): string
    {
        return trim(urldecode((string) (parse_url($path, PHP_URL_PATH) ?? $path)), '/');
    }
}
