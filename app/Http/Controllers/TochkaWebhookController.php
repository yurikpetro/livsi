<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Payments\TochkaGateway;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления Точки об оплате по ссылке (acquiringInternetPayment).
 *
 * Тело — JWT, подписанный банком. Подписи мы не проверяем и телу не верим:
 * берём из него только номер операции и спрашиваем статус у Точки по API,
 * с нашим ключом. Подделанное уведомление в худшем случае заставит сайт
 * лишний раз спросить банк о его же платеже.
 *
 * Отвечаем 200 всегда, даже на мусор: при подписке Точка шлёт пробное
 * уведомление и без 200 подписку не создаёт, а на любой другой ответ
 * повторяет уведомление 30 раз.
 */
class TochkaWebhookController extends Controller
{
    public function __construct(
        private readonly TochkaGateway $gateway,
        private readonly PaymentService $payments,
    ) {}

    public function __invoke(Request $request): Response
    {
        $operationId = self::operationId((string) $request->getContent());
        if ($operationId === null) {
            return response('', 200);
        }

        $payment = Payment::query()->where('gateway', PaymentSettings::TOCHKA)->where('external_id', $operationId)->first();
        if (! $payment) {
            Log::info('Уведомление Точки о платеже не с сайта.', ['operation' => mb_substr($operationId, 0, 64)]);

            return response('', 200);
        }

        $this->payments->sync($this->gateway->refreshStatus($payment));

        return response('', 200);
    }

    /** Номер операции из тела уведомления (JWT или, на всякий случай, JSON). */
    public static function operationId(string $body): ?string
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > 20000) {
            return null;
        }
        $json = json_decode($body, true);
        if (! is_array($json)) {
            $parts = explode('.', $body);
            $json = count($parts) === 3
                ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true)
                : null;
        }
        $id = is_array($json) ? ($json['operationId'] ?? null) : null;

        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? $id : null;
    }
}
