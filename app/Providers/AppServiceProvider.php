<?php

namespace App\Providers;

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
        $this->app->scoped(\App\Services\CartService::class);

        // Провайдера меняют — витрина и заказы не должны о нём знать.
        $this->app->bind(\App\Payments\PaymentGateway::class, \App\Payments\YooKassaGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
