<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Метки кампании запоминаются на первом визите: заявку человек
        // оставляет позже и уже на другой странице.
        // Через туннель (ngrok и подобные) запрос приходит от прокси,
        // и настоящий адрес отправителя лежит в заголовке. Без этого
        // проверка адреса в вебхуке видит 127.0.0.1 и всё отклоняет.
        // На боевом контуре список задаётся адресами балансировщика,
        // а не звёздочкой: иначе адрес отправителя можно подделать.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        $middleware->web(append: [
            \App\Http\Middleware\CaptureUtm::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
