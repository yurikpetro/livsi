<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CatalogQuery;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $query = CatalogQuery::fromRequest($request);
        if ($query->line && $query->tab !== '') {
            return redirect($query->urlForCategory(['line' => $query->line]));
        }

        if ($query->tab === 'pro') {
            $purposes = $query->purposes;
            sort($purposes);
            $line = match ($purposes) {
                ['body', 'face'] => 'pro-skin',
                ['manicure', 'pedicure'] => 'pro-manicure-pedicure',
                default => null,
            };

            if ($line !== null) {
                return redirect($query->urlForCategory(['line' => $line]), 301);
            }
        }

        $facets = $query->facets();

        return view('catalog.index', [
            'query' => $query,
            'facets' => $facets,
            'products' => $query->builder()->paginate(CatalogQuery::PER_PAGE)->withQueryString(),

            // Сортировка не меняет состав выдачи, только порядок, поэтому
            // канонический адрес — без неё. Страница в каноническом остаётся:
            // иначе вторая страница склеилась бы с первой.
            'canonical' => $query->canonicalUrl($request->integer('page') ?: null),

            // Выдачу поиска не индексируем: бесконечные варианты запросов
            // создают мусорные страницы.
            'robots' => $query->q !== '' ? 'noindex, follow' : null,
        ]);
    }

    /**
     * Карточка товара — отдельная страница с собственным адресом.
     * В прототипе товар открывался модалкой поверх /catalog: нечего было
     * индексировать и нельзя было дать ссылку (docs/07-design-review.md §4.1).
     */
    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load(['line', 'images', 'variants.quota', 'purposes', 'tasks', 'reviews', 'declarations']);

        $related = $product->recommendedProducts();

        return view('catalog.show', compact('product', 'related'));
    }
}
