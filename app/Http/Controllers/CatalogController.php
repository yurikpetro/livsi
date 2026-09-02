<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CatalogQuery;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $query  = CatalogQuery::fromRequest($request);
        $facets = $query->facets();

        return view('catalog.index', [
            'query'    => $query,
            'facets'   => $facets,
            'chips'    => $query->chips($facets),
            'products' => $query->builder()->paginate(CatalogQuery::PER_PAGE)->withQueryString(),
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

        $product->load(['line', 'images', 'variants.quota', 'purposes', 'tasks', 'reviews']);

        $related = Product::active()
            ->where('id', '!=', $product->id)
            ->when($product->product_line_id, fn ($q) => $q->where('product_line_id', $product->product_line_id))
            ->with(['line', 'images', 'variants.quota'])
            ->take(4)
            ->get();

        return view('catalog.show', compact('product', 'related'));
    }
}