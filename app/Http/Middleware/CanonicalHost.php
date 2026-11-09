<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Один адрес сайта для поисковиков. Сайт открывается и с www, и без —
 * для Яндекса это два зеркала с одинаковыми страницами, вес делится, и в
 * выдачу может попасть не тот адрес. Переход на второй адрес — постоянная
 * переадресация (301) на адрес из APP_URL.
 *
 * Только для GET и HEAD и только между вариантами «с www / без www» одного
 * домена: формы, вебхуки, служебные адреса и локальная разработка не трогаются.
 */
class CanonicalHost
{
    private const SKIP = ['api/*', 'webhooks/*', 'livewire*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $main = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = strtolower($request->getHost());
        if ($main === '' || $host === $main || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || ! ($host === 'www.'.$main || 'www.'.$host === $main) || $request->is(...self::SKIP)) {
            return $next($request);
        }

        $root = rtrim((string) config('app.url'), '/');

        return redirect()->away($root.$request->getRequestUri(), 301);
    }
}
