<?php

namespace App\Providers;

use App\Models\ProductLine;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('*', function ($view) {
            $view->with([
                'siteTopbar'    => Setting::get('topbar_text', ''),
                'siteWorkHours' => Setting::get('work_hours', ''),
                'siteLines'     => ProductLine::active()->orderBy('sort')->get(),
            ]);
        });

        // Счётчик корзины нужен только в шапке — не дёргаем его на каждом шаблоне.
        View::composer('partials.header', function ($view) {
            $view->with('cartCount', app(CartService::class)->currentOrNull()?->totalQty() ?? 0);
        });
    }
}