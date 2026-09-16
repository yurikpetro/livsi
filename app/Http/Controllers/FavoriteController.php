<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\FavoriteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Избранное.
 *
 * Доступно и гостю: на сайте гостевой сценарий обязателен, а сердечко —
 * последнее место, где уместно требовать вход.
 */
class FavoriteController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites)
    {
    }

    public function index(): View
    {
        return view('pages.favorites', [
            'products' => $this->favorites->products(),
            // Список личный и у каждого свой — индексировать нечего.
            'robots'   => 'noindex, nofollow',
        ]);
    }

    /**
     * Без JavaScript это обычная форма с перенаправлением назад,
     * со скриптом — запрос в фоне: страница не должна прыгать
     * из-за нажатия на сердечко.
     */
    public function toggle(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $liked = $this->favorites->toggle($product);

        if ($request->expectsJson()) {
            return response()->json([
                'liked' => $liked,
                'count' => $this->favorites->count(),
            ]);
        }

        return back()->with('status', $liked
            ? 'Добавлено в избранное.'
            : 'Убрано из избранного.');
    }
}
