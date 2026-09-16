<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ЮKassa.
 *
 * Взят HTTP-клиент Laravel, а не официальный SDK: нужны всего две ручки,
 * зато полный контроль над ключом идемпотентности и простое подставление
 * ответов в тестах. SDK притащил бы зависимость ради того же самого.
 */
class YooKassaGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return filled(config('livsi.yookassa.shop_id'))
            && filled(config('livsi.yookassa.secret_key'));
    }

    /** Тестовый магазин: секретный ключ выдаётся с приставкой `test_`. */
    public function isTestMode(): bool
    {
        return str_starts_with((string) config('livsi.yookassa.secret_key'), 'test_');
    }

    public function createPayment(Order $order): Payment
    {
        $this->guard();

        $receipt = new ReceiptBuilder($order);

        // Сумма платежа берётся из чека, а не из заказа: если они разойдутся
        // хоть на копейку, провайдер отклонит запрос — пусть источник будет один.
        $amount = $receipt->total();

        $payment = $order->payments()->create([
            'gateway'         => 'yookassa',
            'idempotence_key' => (string) Str::uuid(),
            'status'          => Payment::STATUS_PENDING,
            'amount'          => $amount,
        ]);

        $response = $this->client()
            ->withHeaders(['Idempotence-Key' => $payment->idempotence_key])
            ->post('/payments', [
                'amount' => [
                    'value'    => number_format($amount / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                // Списываем сразу: двухстадийная схема нужна там, где товар
                // может не поехать, а у нас есть квота и резерв под заказ.
                'capture'      => true,
                'description'  => 'Заказ ' . $order->number,
                'confirmation' => [
                    'type'       => 'redirect',
                    'return_url' => $order->accessUrl(),
                ],
                'receipt'  => $receipt->build(),
                'metadata' => [
                    'order_id'     => (string) $order->id,
                    'order_number' => $order->number,
                ],
            ]);

        if ($response->failed()) {
            // Запись оставляем: по ней видно, что попытка была, и в поддержке
            // провайдера её можно найти по ключу идемпотентности.
            $payment->update([
                'status'  => Payment::STATUS_CANCELED,
                'payload' => $response->json() ?: ['http_status' => $response->status()],
                'cancellation_reason' => 'Провайдер отклонил создание платежа',
                'cancelled_at'        => now(),
            ]);

            Log::error('ЮKassa не приняла платёж.', [
                'order'  => $order->number,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException('Платёж создать не удалось. Попробуйте ещё раз или напишите нам.');
        }

        return $this->apply($payment, $response->json());
    }

    public function refreshStatus(Payment $payment): Payment
    {
        $this->guard();

        if (! $payment->external_id) {
            return $payment;
        }

        $response = $this->client()->get('/payments/' . $payment->external_id);

        if ($response->failed()) {
            Log::warning('Не удалось получить статус платежа.', [
                'payment' => $payment->id,
                'status'  => $response->status(),
            ]);

            return $payment;
        }

        return $this->apply($payment, $response->json());
    }

    /**
     * Перенести ответ провайдера в запись платежа.
     *
     * Используется и при создании, и при опросе статуса, и при вебхуке —
     * чтобы правило «что считать оплатой» было ровно в одном месте.
     */
    public function apply(Payment $payment, array $data): Payment
    {
        $status = $data['status'] ?? $payment->status;

        $payment->fill([
            'external_id'      => $data['id'] ?? $payment->external_id,
            'status'           => $status,
            'confirmation_url' => $data['confirmation']['confirmation_url'] ?? $payment->confirmation_url,
            'payment_method'   => $data['payment_method']['type'] ?? $payment->payment_method,
            'payload'          => $data,
        ]);

        if ($status === Payment::STATUS_SUCCEEDED && ! $payment->paid_at) {
            $payment->paid_at = now();
        }

        if ($status === Payment::STATUS_CANCELED && ! $payment->cancelled_at) {
            $payment->cancelled_at = now();
            $payment->cancellation_reason = $data['cancellation_details']['reason'] ?? null;
        }

        $payment->save();

        return $payment;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('livsi.yookassa.api_url'))
            ->withBasicAuth(
                (string) config('livsi.yookassa.shop_id'),
                (string) config('livsi.yookassa.secret_key'),
            )
            ->acceptJson()
            ->timeout(20)
            // Сеть иногда моргает; повтор безопасен, потому что запрос
            // идёт с ключом идемпотентности.
            ->retry(2, 300, throw: false);
    }

    private function guard(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Не заданы ключи ЮKassa: YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY.');
        }

        if (! Vat::isConfigured()) {
            throw new RuntimeException(
                'Не определена ставка НДС — чек сформировать нельзя. '
                . 'Задайте её в настройках сайта.',
            );
        }
    }
}
