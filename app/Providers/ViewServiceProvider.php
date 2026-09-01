<?php

namespace App\Providers;

use App\Models\ProductLine;
use App\Models\Setting;
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
    }
}