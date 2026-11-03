<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Дверь для livsipro.ru: заказы сайта для сборки.
 *
 * Пускает по токену из заголовка Authorization: Bearer … и, если задан
 * список адресов, только с них. Токен лежит в .env (LIVSIPRO_API_TOKEN),
 * сравнивается без утечки по времени. Без токена в .env дверь закрыта
 * целиком — забытая настройка не откроет заказы всему интернету.
 */
class LivsiproToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('livsi.livsipro.token');
        if (strlen($token) < 32) {
            return response()->json(['message' => 'Связь с livsipro не настроена.'], 503);
        }
        $allowed = config('livsi.livsipro.ips', []);
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            return response()->json(['message' => 'Доступ с этого адреса закрыт.'], 403);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Неверный ключ доступа.'], 401);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
