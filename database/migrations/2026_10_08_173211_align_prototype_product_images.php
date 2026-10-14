<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{string, string}> */
    private const IMAGES = [
        'multipenka-dlya-ruk-i-stop' => ['fresh-hero.jpg', 'livsi-fresh.png'],
        'krem-dlya-ruk-i-stop-25-urea' => ['warm-portrait.jpg', 'livsi-base.png'],
        'parfyumirovannyy-mist-dlya-tela' => ['sweet-portrait.jpg', 'livsi-sweet.png'],
        'skrab-dlya-tela' => ['mango-product.jpg', 'livsi-warm.png'],
        'keratoliticheskiy-gel-dlya-stop' => ['avatar3.jpg', 'livsi-pro.png'],
        'multipenka-dlya-manikyura-fresh' => ['fresh-hero.jpg', 'product-fresh.png'],
        'multipenka-dlya-manikyura-sweet' => ['sweet-closeup.jpg', 'product-sweet.png'],
    ];

    public function up(): void
    {
        foreach (self::IMAGES as $slug => [$old, $new]) {
            $productId = DB::table('products')->where('slug', $slug)->value('id');
            if ($productId) {
                $detail = match ($slug) {
                    'multipenka-dlya-ruk-i-stop' => 'product-fresh.png',
                    'parfyumirovannyy-mist-dlya-tela' => 'product-sweet.png',
                    'skrab-dlya-tela' => 'mango-product.jpg',
                    default => $new,
                };
                DB::table('product_images')->where('product_id', $productId)->where('is_primary', false)
                    ->whereIn('path', array_map(fn (string $file): string => 'img/catalog/'.$file, ['fresh-hero.jpg', 'sweet-portrait.jpg', 'warm-portrait.jpg', 'mango-product.jpg', 'sweet-closeup.jpg', 'avatar3.jpg', 'avatar4.jpg']))
                    ->update(['path' => 'img/catalog/'.$detail]);
                DB::table('product_images')->where('product_id', $productId)->where('is_primary', true)
                    ->where('path', 'img/catalog/'.$old)->update(['path' => 'img/catalog/'.$new]);
            }
        }

        foreach (['fresh-hero.jpg' => ['livsi-fresh.png', 'multipenka-dlya-ruk-i-stop'], 'sweet-portrait.jpg' => ['livsi-sweet.png', 'parfyumirovannyy-mist-dlya-tela'], 'mango-product.jpg' => ['livsi-warm.png', 'skrab-dlya-tela'], 'warm-portrait.jpg' => ['livsi-base.png', 'krem-dlya-ruk-i-stop-25-urea'], 'avatar4.jpg' => ['livsi-pro.png', 'keratoliticheskiy-gel-dlya-stop']] as $old => [$new, $slug]) {
            DB::table('ugc_items')->where('image_path', 'img/catalog/'.$old)->whereNull('product_id')
                ->update(['image_path' => 'img/catalog/'.$new, 'product_id' => DB::table('products')->where('slug', $slug)->value('id')]);
        }

        foreach (['img/reviews/main-product.png' => 'img/catalog/product-fresh.png', 'img/reviews/top.jpg' => 'img/catalog/livsi-base.png', 'img/reviews/bottom.jpg' => 'img/catalog/livsi-pro.png'] as $old => $new) {
            DB::table('reviews')->where('image_path', $old)->update(['image_path' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::IMAGES as $slug => [$old, $new]) {
            DB::table('product_images')->where('product_id', DB::table('products')->where('slug', $slug)->value('id'))
                ->where('path', 'img/catalog/'.$new)->update(['path' => 'img/catalog/'.$old]);
        }
    }
};
