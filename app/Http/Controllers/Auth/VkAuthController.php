<?php

namespace App\Http\Controllers\Auth;

use App\Auth\VkId;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FavoriteService;
use App\Services\SocialAuthService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VkAuthController extends Controller
{
    private const FLOW = 'auth.vk.flow';

    public function __construct(
        private readonly VkId $vk,
        private readonly SocialAuthService $accounts,
        private readonly FavoriteService $favorites,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (! $this->vk->isConfigured()) {
            return back()->with('status', 'Вход через VK и Mail пока не настроен.');
        }

        $order = $this->verifiedOrder($request);
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::FLOW, [
            'state' => $state,
            'verifier' => $verifier,
            'order_id' => $order?->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($this->vk->authUrl($state, $verifier, $provider));
    }

    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull(self::FLOW);
        $state = $request->query('state');

        if (! is_array($flow) || ! is_string($state)
            || ! hash_equals($flow['state'], $state) || $flow['expires_at'] < now()->timestamp) {
            return redirect()->route('login')->with('status', 'Вход не завершён: страница устарела. Попробуйте ещё раз.');
        }

        $back = $this->backTo($flow['order_id']);

        if ($request->filled('error')) {
            return $back->with('status', 'Вход отменён.');
        }

        $code = $request->query('code');
        $deviceId = $request->query('device_id');

        if (! is_string($code) || $code === '' || ! is_string($deviceId) || $deviceId === '') {
            return $back->with('status', 'Вход не завершён. Попробуйте ещё раз.');
        }

        try {
            $profile = $this->vk->profile($code, $deviceId, $flow['verifier'], $state);
            $user = $this->accounts->login($profile);
        } catch (Throwable $e) {
            /** Текст HTTP-ошибки может содержать токены и контакты. */
            Log::warning('Вход через VK ID не удался.', ['exception' => $e::class]);

            $message = match (true) {
                str_contains($e->getMessage(), 'адрес почты уже занят') => 'Этот адрес почты уже занят другим аккаунтом. Напишите нам — поможем войти.',
                str_contains($e->getMessage(), 'не передал адрес почты') => 'VK ID не передал почту. Разрешите передачу почты или выберите другой способ входа.',
                $e instanceof DomainException => $e->getMessage(),
                default => 'Войти не получилось. Попробуйте позже или выберите другой способ входа.',
            };

            return $back->with('status', $message);
        }

        Auth::login($user, remember: true);
        $this->favorites->mergeInto($user);

        if ($flow['order_id'] && $order = Order::find($flow['order_id'])) {
            $this->accounts->attachOrder($user, $order);
        }

        return ($flow['order_id'] ? $back : redirect()->route('account'))
            ->with('status', $flow['order_id'] ? 'Вы вошли, заказ сохранён.' : 'Вы вошли в аккаунт.');
    }

    private function verifiedOrder(Request $request): ?Order
    {
        $number = $request->string('order')->trim()->value();
        $token = $request->string('token')->value();

        if ($number === '' || $token === '') {
            return null;
        }

        $order = Order::where('number', $number)->first();

        return $order && hash_equals($order->access_token, $token) ? $order : null;
    }

    private function backTo(?int $orderId): RedirectResponse
    {
        $order = $orderId ? Order::find($orderId) : null;

        return $order ? redirect()->to($order->accessUrl()) : redirect()->route('login');
    }
}
