<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\YooKassaGateway;
use App\Services\CartService;
use App\Services\CustomerDirectory;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(PaymentGateway::class, YooKassaGateway::class);
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
    }
}
