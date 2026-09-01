<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Purpose;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Каталог с тремя независимыми осями навигации (docs/06-scope-v2.md §3):
 * линейка — одна на товар, назначение и задача — many-to-many.
 *
 * Каждая комбинация фильтров живёт по собственному адресу — в прототипе
 * фильтры работали только в браузере и не давали индексируемых страниц.
 */
class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::active()->with(['line', 'images', 'variants.quota']);

        // Ось 1: ароматическая линейка.
        if ($line = $request->string('line')->toString()) {
            $query->whereHas('line', fn ($q) => $q->where('code', $line));
        }

        // Ось 2: назначение.
        if ($purpose = $request->string('purpose')->toString()) {
            $query->whereHas('purposes', fn ($q) => $q->where('code', $purpose));
        }

        // Ось 3: задача.
        if ($task = $request->string('task')->toString()) {
            $query->whereHas('tasks', fn ($q) => $q->where('code', $task));
        }

        // Быстрые подборки поверх осей.
        match ($request->string('tab')->toString()) {
            'pro'     => $query->where('is_pro', true),
            'bundles' => $query->where('is_bundle', true),
            'new'     => $query->where('badge', 'new'),
            'best'    => $query->where('badge', 'best'),
            default   => null,
        };

        $sort = $request->string('sort')->toString() ?: 'popular';

        match ($sort) {
            'price_asc'  => $query->orderBy(
                \App\Models\ProductVariant::select('price')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->orderBy('price')
                    ->limit(1)
            ),
            'price_desc' => $query->orderByDesc(
                \App\Models\ProductVariant::select('price')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->orderBy('price')
                    ->limit(1)
            ),
            'rating'     => $query->orderByDesc('rating'),
            default      => $query->orderBy('sort'),
        };

        return view('catalog.index', [
            'products' => $query->get(),
            'lines'    => ProductLine::active()->orderBy('sort')->get(),
            'purposes' => Purpose::where('is_active', true)->orderBy('sort')->get(),
            'tasks'    => Task::where('is_active', true)->orderBy('sort')->get(),
            'filters'  => [
                'line'    => $line ?? null,
                'purpose' => $purpose ?? null,
                'task'    => $task ?? null,
                'tab'     => $request->string('tab')->toString(),
                'sort'    => $sort,
            ],
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