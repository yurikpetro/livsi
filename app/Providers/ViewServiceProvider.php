<?php

namespace App\Providers;

use App\Models\LegalPage;
use App\Models\ProductLine;
use App\Models\SellerProfile;
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

                // Подвал показывает реквизиты продавца и ссылки на документы
                // на каждой странице — этого требует дистанционная торговля.
                'siteSeller'     => SellerProfile::current(),
                'siteLegalPages' => LegalPage::active()->orderBy('sort')->get(),
            ]);
        });
    }
}