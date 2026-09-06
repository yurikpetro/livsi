<?php

namespace App\Http\Controllers;

use App\Models\FaqItem;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Review;
use App\Models\UgcItem;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('home', [
            'lines'    => ProductLine::active()->orderBy('sort')->get(),
            'products' => Product::active()
                ->with(['line', 'images', 'variants.quota'])
                ->orderBy('sort')
                ->take(8)
                ->get(),
            'reviews'  => Review::homepageBlock(),
            'ugc'      => UgcItem::where('is_active', true)->orderBy('sort')->get(),
            'faq'      => FaqItem::where('is_active', true)->orderBy('sort')->get(),
        ]);
    }
}