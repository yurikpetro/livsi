<?php

namespace App\Http\Controllers;

use App\Delivery\DeliveryGateway;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Setting;
use App\Payments\PaymentGateway;
use App\Services\CartService;
use App\Services\DeliveryQuoteService;
use App\Services\DeliverySelection;
use App\Services\OrderMailer;
use App\Services\OrderService;
use App\Services\OzonDeliveryService;
use App\Services\PaymentService;
use App\Services\PromoCodeService;
use App\Services\PromoService;
use App\Support\Text;
use App\Support\Utm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
        private readonly OrderMailer $mailer,
        private readonly DeliveryGateway $apiShip,
        private readonly PromoService $promos,
        private readonly DeliveryQuoteService $quotes,
        private readonly OzonDeliveryService $ozon,
        private readonly PromoCodeService $promoCodes,
    ) {}

    public function index(Request $request)
    {
        $cart = $this->carts->currentOrNull();

        if (! $cart || $cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Корзина пуста.');
        }

        return view('pages.checkout', [
            'cart' => $cart,
            'promo' => $this->appliedPromo($request, $cart),
            'gateway' => $this->gateway,
            'deliveryCalculationEnabled' => $this->quotes->enabled(),
            'deliveryCalculationTest' => $this->quotes->testing(),
            'courierEnabled' => DeliveryQuoteService::courierEnabled() && $this->quotes->enabled(),
            'courierPrice' => (int) Setting::get('stavropol_courier_price', 19900),
            'courierNote' => trim((string) Setting::get('stavropol_courier_note', '')),
            'remainingToFreeShipping' => $this->promos->remainingToFreeShipping($cart->subtotal()),
            'pointMethods' => array_values(array_filter(
                DeliverySelection::pickupMethods(),
                fn (string $method) => ($key = DeliverySelection::providerKey($method)) && $this->apiShip->isProviderActive($key),
            )),
            'ozonClientCheckEnabled' => $this->apiShip->isProviderActive('ozondel'),
            'mapsApiKey' => (string) config('livsi.delivery.maps_api_key'),
            'suggestApiKey' => (string) config('livsi.delivery.suggest_api_key'),
            // Личная страница: в поиске ей делать нечего.
            'robots' => 'noindex, nofollow',
        ]);
    }

    /** «Применить» промокод: проверяем и запоминаем в сессии оформления. */
    public function applyPromo(Request $request): RedirectResponse
    {
        $cart = $this->carts->currentOrNull();
        if (! $cart || $cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Корзина пуста.');
        }
        $data = $request->validate(['promo_code' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40']],
            ['promo_code.required' => 'Введите промокод.']);
        $email = (string) ($data['email'] ?? $request->user()?->email ?? '');
        try {
            $promo = $this->promoCodes->check($data['promo_code'], $this->goods($cart), $email, $data['phone'] ?? null, $request->user());
        } catch (ValidationException $exception) {
            $request->session()->forget('checkout_promo');

            return redirect()->route('checkout.index')->withInput($request->except('_token'))->withErrors($exception->errors());
        }
        $request->session()->put('checkout_promo', ['code' => $promo['promo']->code, 'free_delivery' => $promo['free_delivery'],
            'email' => $email, 'phone' => $data['phone'] ?? null]);

        return redirect()->route('checkout.index')->withInput($request->except(['_token', 'delivery_quote_token']))
            ->with('status', 'Промокод '.$promo['promo']->code.' применён: '.mb_strtolower($promo['title']).'.');
    }

    public function removePromo(Request $request): RedirectResponse
    {
        $request->session()->forget('checkout_promo');

        return redirect()->route('checkout.index')->withInput($request->except(['_token', 'delivery_quote_token']));
    }

    /** Применённый промокод для страницы оформления; устаревший — убираем. */
    private function appliedPromo(Request $request, $cart): ?array
    {
        $stored = $request->session()->get('checkout_promo');
        if (! is_array($stored) || empty($stored['code'])) {
            return null;
        }
        try {
            $promo = $this->promoCodes->check($stored['code'], $this->goods($cart), (string) ($stored['email'] ?? ''), $stored['phone'] ?? null, $request->user());
        } catch (ValidationException $exception) {
            $request->session()->forget('checkout_promo');
            $request->session()->now('promo_lost', $exception->validator->errors()->first());

            return null;
        }

        return ['code' => $promo['promo']->code, 'title' => $promo['title'], 'discount' => $promo['discount'], 'free_delivery' => $promo['free_delivery']];
    }

    /** Товары к оплате после подарка — от этой суммы считается скидка по промокоду. */
    private function goods($cart): int
    {
        $subtotal = $cart->subtotal();
        $gift = $cart->giftItem();
        $giftDiscount = $gift && $this->promos->isGiftUnlocked($subtotal) ? (int) $gift->variant->price : 0;

        return max(0, $subtotal - min($giftDiscount, $subtotal));
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $cart = $this->carts->currentOrNull();

        if (! $cart || $cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Корзина пуста.');
        }

        $requestedMethod = $request->validated('delivery_method');
        $pointId = $requestedMethod === DeliverySelection::STAVROPOL_COURIER ? null : $request->validated('delivery_point_id');
        $pointMethod = $requestedMethod === DeliverySelection::PICKUP_POINT
            ? $request->validated('delivery_point_method')
            : $requestedMethod;
        $providerKey = DeliverySelection::providerKey($pointMethod);

        if ($providerKey === 'ozondel') {
            try {
                $canDeliver = $this->ozon->canDeliver($request->validated('phone'), $request->session()->getId());
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                return back()->withInput()->withErrors(['delivery_point_id' => 'Не удалось проверить доставку Ozon. Повторите проверку или выберите другую службу.']);
            }
            if (! $canDeliver) {
                return back()->withInput()->withErrors(['delivery_point_id' => 'Ozon не подтвердил доставку на этот номер. Проверьте номер в аккаунте Ozon или выберите другую службу.']);
            }
        }

        if ($requestedMethod === DeliverySelection::STAVROPOL_COURIER && ! DeliveryQuoteService::courierEnabled()) {
            return back()->withInput()->withErrors(['delivery_method' => 'Курьерская доставка сейчас недоступна. Выберите пункт выдачи.']);
        }
        if (! $this->quotes->enabled()) {
            return back()->withInput()->withErrors(['delivery_quote' => 'Расчёт доставки временно недоступен. Попробуйте позже.']);
        }
        if (! $request->filled('delivery_quote_token')) {
            return back()->withInput()->withErrors(['delivery_quote' => $requestedMethod === DeliverySelection::STAVROPOL_COURIER
                ? 'Дождитесь расчёта доставки курьером перед оплатой.'
                : 'Выберите пункт выдачи и дождитесь расчёта доставки перед оплатой.']);
        }
        $quote = $this->quotes->accept($request->validated('delivery_quote_token'), $cart, [
            'delivery_city' => $request->validated('delivery_city'),
            'delivery_region' => $request->validated('delivery_region') ?: null,
            'delivery_point_method' => $pointMethod,
            'delivery_point_id' => $pointId,
        ], $request->session()->getId());
        $point = $quote['point'];

        try {
            $order = $this->orders->createFromCart($cart, [
                'user_id' => $request->user()?->id,
                'customer_name' => Text::utf8($request->string('name')->trim()->value()),
                'customer_phone' => Text::utf8($request->string('phone')->trim()->value()),
                'customer_email' => Text::utf8($request->string('email')->trim()->value()),
                'comment' => Text::utf8($request->string('comment')->trim()->value()) ?: null,
                'delivery_method' => $pointMethod,
                'delivery_price' => $quote['price'],
                'delivery_quote' => $quote,
                'delivery_country_code' => config('livsi.delivery.country_code'),
                'delivery_city' => Text::utf8($point['city'] ?? $request->string('delivery_city')->trim()->value()),
                'delivery_region' => Text::utf8($request->string('delivery_region')->trim()->value()) ?: null,
                'delivery_address' => $request->validated('delivery_method') === DeliverySelection::STAVROPOL_COURIER
                    ? (Text::utf8($request->string('delivery_address')->trim()->value()) ?: null)
                    : null,
                'delivery_point_hint' => $request->validated('delivery_method') !== DeliverySelection::STAVROPOL_COURIER
                    ? (Text::utf8($request->string('delivery_point_hint')->trim()->value()) ?: null)
                    : null,
                'delivery_point_id' => $point['id'] ?? null,
                'delivery_point_address' => $point['address'] ?? null,
                'consent_at' => now(),
                'marketing_consent_at' => $request->boolean('marketing_consent') ? now() : null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'utm' => Utm::current($request),
            ], $request->session()->get('checkout_promo.code') ?: ($request->string('promo_code')->trim()->value() ?: null));
        } catch (ValidationException $e) {
            // Промокод перестал подходить (истёк, использован в другом заказе) —
            // снимаем его; доставку нужно пересчитать, если он давал бесплатную.
            $request->session()->forget('checkout_promo');

            return back()->withInput()->withErrors($e->errors());
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
            $this->payments->cancelIfNoLivePayment($order->id, notify: false);

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
            ? $message.' ['.$e->getMessage().']'
            : $message;
    }
}
