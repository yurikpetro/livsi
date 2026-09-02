<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLine;

/**
 * Посадочные страницы линеек и раздела PRO.
 *
 * Отдельные адреса, а не фильтр каталога: у линейки есть своё описание,
 * свои цвета и свой SEO-текст, и на неё ведут ссылки из брендовых
 * материалов. Товары те же, что в каталоге с фильтром.
 */
class LineController extends Controller
{
    public function show(ProductLine $line)
    {
        abort_unless($line->is_active, 404);

        return view('catalog.line', [
            'line'     => $line,
            'isPro'    => false,
            'products' => Product::active()
                ->where('product_line_id', $line->id)
                ->with(['line', 'images', 'variants.quota'])
                ->orderBy('sort')
                ->get(),
        ]);
    }

    /** PRO — это флаг на товаре, а не пятая линейка (docs/06-scope-v2.md §3). */
    public function pro()
    {
        return view('catalog.line', [
            'line'     => null,
            'isPro'    => true,
            'products' => Product::active()
                ->where('is_pro', true)
                ->with(['line', 'images', 'variants.quota'])
                ->orderBy('sort')
                ->get(),
        ]);
    }
}