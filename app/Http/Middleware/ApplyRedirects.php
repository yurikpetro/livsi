<?php

namespace App\Http\Middleware;

use App\Models\SeoRedirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Переадресации, заданные владельцем в «Аналитика сайта» → «Битые ссылки».
 * Срабатывают раньше страницы: старый адрес может совпасть с живым
 * маршрутом (например, /catalog/старый-товар).
 */
class ApplyRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        try {
            $map = SeoRedirect::map();
        } catch (Throwable) {
            // Таблицы ещё нет (до миграции) или база недоступна — сайт работает как раньше.
            return $next($request);
        }
        if ($map === []) {
            return $next($request);
        }

        $path = SeoRedirect::normalize($request->getPathInfo());
        $target = $map[$path] ?? null;
        if ($target === null || SeoRedirect::normalize($target) === $path) {
            return $next($request);
        }
        // Две переадресации друг на друга (А → Б, Б → А) — не гоняем браузер по кругу.
        $back = $map[SeoRedirect::normalize($target)] ?? null;
        if ($back !== null && SeoRedirect::normalize($back) === $path) {
            return $next($request);
        }

        try {
            DB::table('seo_redirects')->where('from_path', $path)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);
        } catch (Throwable) {
            // Счётчик не важнее переадресации.
        }

        // Метки рекламы и прочие параметры старой ссылки не теряем.
        $query = $request->getQueryString();

        return redirect()->to($target.($query ? (str_contains($target, '?') ? '&' : '?').$query : ''), 301);
    }
}
