<?php

namespace App\Providers;

use App\Models\LegalPage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\PaymentGateway;
use App\Services\CartService;
use App\Seo\IndexNow;
use App\Services\CustomerDirectory;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Одна корзина на запрос: сервис резолвится и в контроллере,
        // и в композере шапки — без scoped получились бы две разные корзины.
        $this->app->scoped(CartService::class);

        // Провайдера меняют — витрина и заказы не должны о нём знать.
        // Кто принимает новые платежи, выбирается в «Интеграциях оплаты».
        $this->app->bind(PaymentGateway::class, GatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ozon-clients', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('delivery-quotes', fn (Request $request): Limit => Limit::perMinute(15)->by($request->ip()));
        RateLimiter::for('delivery-points', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('livsipro', fn (Request $request): Limit => Limit::perMinute(240)->by($request->ip()));
        RateLimiter::for('delivery-geocode', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        // Кнопки в шапке страницы админки переносятся на вторую строку, а не
        // уезжают за правый край (в заказе их пять-шесть).
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
            '<style>.fi-header-actions-ctn{flex-shrink:1;min-width:0}.fi-header-actions-ctn .fi-ac{justify-content:flex-end}</style>'
        ));

        // Каждый заказ и каждый новый пользователь — в справочник клиентов.
        Order::created(fn (Order $order) => app(CustomerDirectory::class)->attachOrder($order));
        User::created(fn (User $user) => app(CustomerDirectory::class)->attachUser($user));

        // Изменилась страница товара, линейки или документа — сайт сам скажет Яндексу (IndexNow).
        $product = fn (Product $product) => IndexNow::queue(route('catalog.show', $product), route('catalog.index'));
        Product::saved($product);
        Product::deleted($product);
        ProductVariant::saved(fn (ProductVariant $variant) => $variant->product ? IndexNow::queue(route('catalog.show', $variant->product)) : null);
        ProductLine::saved(fn (ProductLine $line) => IndexNow::queue(route('catalog.line', $line)));
        LegalPage::saved(fn (LegalPage $page) => Route::has('legal.'.$page->slug) ? IndexNow::queue(route('legal.'.$page->slug)) : null);
    }
}
