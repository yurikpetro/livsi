<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductLine;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('catalog.index'), 'priority' => '0.9'],
            ['loc' => route('catalog.pro'), 'priority' => '0.8'],
            ['loc' => route('declarations'), 'priority' => '0.7'],
            ['loc' => route('partners'), 'priority' => '0.6'],
            ['loc' => route('contract'), 'priority' => '0.6'],
        ];

        foreach (ProductLine::active()->orderBy('sort')->get() as $line) {
            $urls[] = [
                'loc'      => route('catalog.line', $line),
                'priority' => '0.8',
                'lastmod'  => $line->updated_at?->toAtomString(),
            ];
        }

        foreach (Product::active()->orderBy('sort')->get() as $product) {
            $urls[] = [
                'loc'      => route('catalog.show', $product),
                'priority' => '0.7',
                'lastmod'  => $product->updated_at?->toAtomString(),
            ];
        }

        // Страницы фильтров и поиска в карту не кладём: фильтры индексируются
        // сами по ссылкам, а выдача поиска закрыта от индексации.

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }
}