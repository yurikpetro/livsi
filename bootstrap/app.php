<?php

use App\Http\Middleware\ApplyRedirects;
use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\CaptureUtm;
use App\Seo\MissingPages;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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

        // Один адрес сайта: www.livsi.shop → livsi.shop (или наоборот — как в APP_URL).
        $middleware->web(prepend: [
            CanonicalHost::class,
            // Переадресации из «Битых ссылок» — раньше страницы: старый адрес
            // может совпасть с живым маршрутом.
            ApplyRedirects::class,
        ]);

        $middleware->web(append: [
            CaptureUtm::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Боты-сканеры шлют в Livewire загрузку файла туда, где её нет.
        // Это отказ, а не ошибка сайта: короткий ответ 400, без сообщения в «Ошибки».
        $exceptions->dontReport(MissingFileUploadsTraitException::class);
        $exceptions->render(fn (MissingFileUploadsTraitException $e) => response('Bad request', 400));

        // 404 — в список «Битые ссылки» админки (страница при этом прежняя).
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof NotFoundHttpException
                || $e instanceof ModelNotFoundException) {
                MissingPages::record($request);
            }

            return null;
        });
    })->create();
