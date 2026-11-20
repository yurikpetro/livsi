<?php

use App\Models\Product;
use App\Seo\MissingPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Обновление 18.2, данные.
 *
 * 1. Адреса товаров. У шести мультипенок одно название, и адреса им давали,
 *    дописывая буквы («…multipenkaаа», «…multipenkaaaaa», с пробелом). Товарам
 *    с одинаковым названием адрес собирается из названия и аромата
 *    (…-greipfrut-zhasmin), адресам с кириллицей и пробелами — из названия.
 *    Со старых адресов сайт сам ведёт на новые (Product::updated → SeoRedirect).
 * 2. «Битые ссылки»: убираем сканеры, которые раньше проходили фильтр.
 *
 * Отката нет и не нужно: новые адреса и переадресации работают и в 18.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && Schema::hasTable('seo_redirects')) {
            $products = Product::query()->orderBy('id')->get();
            $titles = $products->countBy(fn (Product $product): string => mb_strtolower(trim((string) $product->title)));
            foreach ($products as $product) {
                $twins = ($titles[mb_strtolower(trim((string) $product->title))] ?? 0) > 1;
                $broken = ! preg_match(Product::SLUG_PATTERN, (string) $product->slug);
                if (! $twins && ! $broken) {
                    continue;
                }
                $text = $twins && filled($product->aroma) ? $product->title.' '.$product->aroma : $product->title;
                $slug = Product::uniqueSlug((string) $text, $product->id);
                if ($slug !== $product->slug) {
                    $product->slug = $slug;
                    $product->save();
                }
            }
        }

        if (Schema::hasTable('missing_pages')) {
            MissingPages::purgeJunk();
        }
    }

    public function down(): void
    {
        //
    }
};
