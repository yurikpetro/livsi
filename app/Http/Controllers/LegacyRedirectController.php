<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Адреса старого сайта livsi.shop (/magazin/…, /product-category/… и т. п.)
 * ещё лежат в выдаче Яндекса. Без переадресации они отдают 404, Яндекс их
 * выкидывает, а вес бренда пропадает. Постоянная переадресация (301): на тот
 * же товар, если его можно узнать по адресу, иначе — в каталог.
 *
 * Срабатывает только для адресов, которых на новом сайте нет.
 */
class LegacyRedirectController extends Controller
{
    /** Начала адресов старого магазина. */
    private const LEGACY = '#^(magazin|shop|product|product-category|product-tag|tovar|tovary|katalog|catalog|category|tag)(/|$)#';

    /** Слова, по которым товары не различить. */
    private const COMMON = ['dlya', 'livsi', 'professional', 'premium', 'magazin', 'category', 'product', 'tovar'];

    public function __invoke(Request $request): RedirectResponse
    {
        $path = trim(urldecode($request->path()), '/');
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $path === '') {
            throw new NotFoundHttpException;
        }
        $segments = array_values(array_filter(explode('/', mb_strtolower($path))));

        // Тот же адрес товара, что и раньше, — только под другим началом.
        foreach (array_reverse($segments) as $segment) {
            $product = Product::active()->where('slug', Str::slug($segment))->first();
            if ($product) {
                return redirect()->route('catalog.show', $product, 301);
            }
        }

        if (! preg_match(self::LEGACY, $path)) {
            throw new NotFoundHttpException;
        }

        // Похожий товар: не меньше двух значимых слов из старого адреса в новом.
        $words = array_values(array_diff(array_filter(explode('-', Str::slug((string) end($segments))),
            fn (string $word): bool => mb_strlen($word) >= 4), self::COMMON));
        if (count($words) >= 2) {
            $best = null;
            $score = 1;
            foreach (Product::active()->get(['id', 'slug']) as $product) {
                $hits = count(array_intersect($words, explode('-', $product->slug)));
                if ($hits > $score) {
                    [$best, $score] = [$product, $hits];
                }
            }
            if ($best) {
                return redirect()->route('catalog.show', $best, 301);
            }
        }

        return redirect()->route('catalog.index', [], 301);
    }
}
