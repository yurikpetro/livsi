<?php

namespace App\Http\Controllers;

use App\Auth\YandexId;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
        private readonly YandexId $yandex,
    ) {
    }

    /**
     * Заказ по ссылке или из кабинета.
     *
     * Гость видит свой заказ по неугадываемому токену (docs/06-scope-v2.md
     * § 2.7) — кабинета у него нет. Владельцу токен не нужен: он уже вошёл,
     * и требовать от него ссылку из письма значило бы, что кабинет
     * бесполезен.
     *
     * Сравнение токена постоянное по времени: обычное сравнение строк
     * утекает информацию о том, сколько символов совпало.
     */
    public function show(Request $request, string $order): View
    {
        $record = Order::with('items')->where('number', $order)->first();

        if (! $record || ! $this->allowed($request, $record)) {
            throw new NotFoundHttpException;
        }

        $payment = $record->lastPayment();

        // Возврат с платёжной страницы приходит раньше вебхука, поэтому
        // статус спрашиваем сами — иначе человек увидит «ждём оплату»
        // сразу после того, как заплатил.
        if ($payment && ! $payment->isFinal() && $this->gateway->isConfigured()) {
            $payment = $this->gateway->refreshStatus($payment);
            $record  = $this->payments->sync($payment);
            $record->load('items');
        }

        return view('pages.order', [
            'order'   => $record,
            'payment' => $payment,
            // Без ключей провайдера кнопка входа ведёт в тупик — не показываем.
            'canLinkAccount' => $this->yandex->isConfigured(),
            // Заказ доступен по токену — индексировать его нельзя.
            'robots'  => 'noindex, nofollow',
        ]);
    }

    private function allowed(Request $request, Order $order): bool
    {
        if ($order->user_id !== null && $order->user_id === $request->user()?->id) {
            return true;
        }

        return hash_equals($order->access_token, (string) $request->query('token'));
    }
}
