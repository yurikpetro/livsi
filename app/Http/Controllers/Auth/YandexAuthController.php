<?php

namespace App\Http\Controllers\Auth;

use App\Auth\YandexId;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FavoriteService;
use App\Services\SocialAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Вход через Яндекс ID.
 *
 * Сценарий заказчика: человек оформил заказ гостем, увидел страницу заказа
 * и одной кнопкой завёл аккаунт, к которому этот заказ и привязался.
 */
class YandexAuthController extends Controller
{
    /** Ключи в сессии живут между двумя запросами: уходом к провайдеру и возвратом. */
    private const STATE = 'auth.yandex.state';

    private const ATTACH_ORDER = 'auth.attach_order';

    public function __construct(
        private readonly YandexId $yandex,
        private readonly SocialAuthService $accounts,
        private readonly FavoriteService $favorites,
    ) {
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->yandex->isConfigured()) {
            return back()->with('status', 'Вход через Яндекс пока не настроен.');
        }

        // Право на заказ проверяем здесь, до ухода к провайдеру: в сессию
        // кладём только идентификатор, а не токен из ссылки.
        $order = $this->verifiedOrder($request);

        $state = Str::random(40);

        $request->session()->put(self::STATE, $state);
        $request->session()->put(self::ATTACH_ORDER, $order?->id);

        return redirect()->away($this->yandex->authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE);
        $orderId  = $request->session()->pull(self::ATTACH_ORDER);

        $back = $this->backTo($orderId);

        // Человек нажал «Отказать» на экране согласия — это не ошибка.
        if ($request->filled('error')) {
            return $back->with('status', 'Вход отменён.');
        }

        // Без совпадения state чужой мог бы подсунуть свой код авторизации
        // и привязать наш аккаунт к своему Яндексу.
        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $back->with('status', 'Вход не завершён: страница устарела. Попробуйте ещё раз.');
        }

        try {
            $profile = $this->yandex->profile((string) $request->query('code'));
            $user    = $this->accounts->login($profile);
        } catch (Throwable $e) {
            Log::error('Вход через Яндекс не удался.', ['error' => $e->getMessage()]);

            return $back->with('status', $this->failure($e));
        }

        // Laravel сам пересоздаёт сессию при входе — без этого чужой
        // идентификатор сессии остался бы рабочим уже от имени вошедшего.
        Auth::login($user, remember: true);

        // Отложенное гостем переносим в аккаунт: иначе избранное выглядело бы
        // потерянным — только что было и пропало после входа.
        $this->favorites->mergeInto($user);

        if ($orderId && $order = Order::find($orderId)) {
            $this->accounts->attachOrder($user, $order);
        }

        return $back->with('status', 'Аккаунт создан, заказ сохранён.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Вы вышли из аккаунта.');
    }

    /**
     * Заказ из ссылки, по которой человек пришёл.
     *
     * Токен сравнивается постоянным по времени способом — как и на самой
     * странице заказа: обычное сравнение строк выдаёт, сколько символов
     * совпало.
     */
    private function verifiedOrder(Request $request): ?Order
    {
        $number = $request->string('order')->trim()->value();
        $token  = $request->string('token')->value();

        if ($number === '' || $token === '') {
            return null;
        }

        $order = Order::where('number', $number)->first();

        return $order && hash_equals($order->access_token, $token) ? $order : null;
    }

    private function backTo(?int $orderId): RedirectResponse
    {
        $order = $orderId ? Order::find($orderId) : null;

        return $order
            ? redirect()->to($order->accessUrl())
            : redirect()->route('home');
    }

    /**
     * Причина отказа показывается человеку, только когда он может на неё
     * повлиять: занятая почта — его случай, сбой сети провайдера — наш.
     */
    private function failure(Throwable $e): string
    {
        return str_contains($e->getMessage(), 'занят')
            ? $e->getMessage()
            : 'Войти через Яндекс не получилось. Попробуйте позже.';
    }
}
