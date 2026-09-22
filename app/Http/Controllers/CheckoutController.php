<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Payments\PaymentGateway;
use App\Services\CartService;
use App\Services\OrderMailer;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Text;
use App\Support\Utm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
        private readonly OrderMailer $mailer,
    ) {
    }

    public function index(Request $request)
    {
        $cart = $this->carts->currentOrNull();

        if (! $cart || $cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Корзина пуста.');
        }

        return view('pages.checkout', [
            'cart'    => $cart,
            'gateway' => $this->gateway,
            // Личная страница: в поиске ей делать нечего.
            'robots'  => 'noindex, nofollow',
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $cart = $this->carts->currentOrNull();

        if (! $cart || $cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Корзина пуста.');
        }

        try {
            $order = $this->orders->createFromCart($cart, [
                'user_id'        => $request->user()?->id,
                'customer_name'  => Text::utf8($request->string('name')->trim()->value()),
                'customer_phone' => Text::utf8($request->string('phone')->trim()->value()),
                'customer_email' => Text::utf8($request->string('email')->trim()->value()),
                'comment'        => Text::utf8($request->string('comment')->trim()->value()) ?: null,
                'consent_at'     => now(),
                'marketing_consent_at' => $request->boolean('marketing_consent') ? now() : null,
                'ip'             => $request->ip(),
                'user_agent'     => mb_substr((string) $request->userAgent(), 0, 255),
                'utm'            => Utm::current($request),
            ]);
        } catch (Throwable $e) {
            // Заказ не создан — корзину не трогаем, человек может поправить
            // количество и попробовать снова.
            return back()->withInput()->withErrors(['cart' => $e->getMessage()]);
        }

        try {
            $payment = $this->gateway->createPayment($order);
        } catch (Throwable $e) {
            // Заказ уже есть, а платёж не создан: снимаем резерв, чтобы товар
            // не завис недоступным.
            $this->payments->releaseReserve($order);

            Log::error('Не удалось создать платёж.', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['payment' => $this->paymentFailure($e)]);
        }

        // Корзина очищается только после успешного создания платежа:
        // если провайдер отказал, человек возвращается к своим товарам.
        $this->carts->clear($cart);

        // Письмо со ссылкой на заказ — гостю оно заменяет личный кабинет.
        // Отправляем до перенаправления на оплату: человек может её не
        // завершить, но заказ у него уже есть и открывается по ссылке.
        $this->mailer->placed($order);

        return redirect()->away($payment->confirmation_url);
    }

    /**
     * Что показать покупателю, когда платёж не создался.
     *
     * Причина всегда одна из трёх, и ни одна не для его глаз: провайдер
     * отклонил запрос (объяснение ничего ему не даст), не задана ставка НДС
     * или нет корневых сертификатов (это наши недоделки, а не его дело).
     * Текст исключения к тому же показывал бы внутренние адреса — покупатель
     * видел `api.yookassa.ru` и ссылку на документацию curl.
     *
     * Настоящая причина уходит в лог. На экран она попадает только при
     * включённом APP_DEBUG: без этого отладка сводится к чтению логов
     * на каждую попытку, а на бою отладка выключена.
     */
    private function paymentFailure(Throwable $e): string
    {
        $message = 'Оплата сейчас недоступна. Мы уже знаем о сбое — попробуйте позже или напишите нам.';

        return config('app.debug')
            ? $message . ' [' . $e->getMessage() . ']'
            : $message;
    }
}
