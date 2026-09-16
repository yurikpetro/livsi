<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\YooKassaGateway;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Уведомления ЮKassa о смене статуса платежа.
 *
 * Подписи у этих уведомлений нет — провайдер предлагает проверять адрес
 * отправителя и перепроверять статус запросом к API. Делаем и то, и другое:
 * без проверки это открытая ручка, которой можно объявить любой заказ
 * оплаченным, а тело уведомления подделывается тривиально.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly YooKassaGateway $gateway,
        private readonly PaymentService $payments,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->fromProvider($request)) {
            Log::warning('Уведомление об оплате с постороннего адреса.', ['ip' => $request->ip()]);

            return response('', 403);
        }

        $externalId = $request->input('object.id');

        if (! $externalId) {
            return response('', 400);
        }

        $payment = Payment::where('external_id', $externalId)->first();

        if (! $payment) {
            // Отвечаем 200: иначе провайдер будет слать это уведомление
            // сутки. Платежа с таким идентификатором у нас нет — и не будет.
            Log::warning('Уведомление о неизвестном платеже.', ['external_id' => $externalId]);

            return response('', 200);
        }

        // Уведомлению не верим: статус берём из API. Тело запроса
        // подделать легко, ответ по Basic-аутентификации — нет.
        $payment = $this->gateway->refreshStatus($payment);

        $this->payments->sync($payment);

        // Повторное уведомление о том же платеже безопасно: переходы
        // идемпотентны, второй раз со склада не спишется.
        return response('', 200);
    }

    private function fromProvider(Request $request): bool
    {
        $allowed = (array) config('livsi.yookassa.webhook_ips');

        return $allowed === [] || IpUtils::checkIp((string) $request->ip(), $allowed);
    }
}
