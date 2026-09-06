<?php

namespace App\Http\Middleware;

use App\Support\Utm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запоминает метки кампании при первом визите, чтобы заявка знала,
 * из какой рекламы пришёл человек.
 */
class CaptureUtm
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            Utm::remember($request);
        }

        return $next($request);
    }
}
