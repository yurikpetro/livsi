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
            ['loc' => route('contacts'), 'priority' => '0.5'],
        ];

        // Юридические документы индексируются: на них ссылаются
        // из чеков и писем, и они должны находиться поиском.
        foreach (\App\Models\LegalPage::active()->orderBy('sort')->get() as $legal) {
            $urls[] = [
                'loc'      => route('legal.' . $legal->slug),
                'priority' => '0.4',
                'lastmod'  => $legal->updated_at?->toAtomString(),
            ];
        }

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