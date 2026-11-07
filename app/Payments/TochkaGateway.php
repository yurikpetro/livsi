<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Интернет-эквайринг Точка Банка: платёжная ссылка с чеком.
 *
 * Покупатель уходит на страницу Точки (СБП, карта), платит и возвращается
 * на страницу заказа. Первый чек «предоплата 100 %» пробивает касса,
 * подключённая к эквайрингу (CloudKassir), — по позициям, которые мы
 * передаём при создании ссылки. Второй чек и чеки возврата — ReceiptService
 * и RefundService через CloudKassir напрямую.
 *
 * Статусы Точки переводим в свои (они же — статусы ЮKassa), чтобы заказы,
 * склад и письма не знали, кто принял деньги.
 */
class TochkaGateway implements PaymentGateway
{
    /** Код ставки НДС (как в Vat) → ставка в чеке Точки. */
    public const VAT_TYPES = [1 => 'none', 2 => 'vat0', 3 => 'vat10', 7 => 'vat5', 8 => 'vat7', 11 => 'vat22'];

    /** Статусы Точки, при которых деньги у нас (возврат учитывается отдельно). */
    private const PAID = ['APPROVED', 'ON-REFUND', 'REFUNDED', 'REFUNDED_PARTIALLY'];

    public function __construct(private readonly TochkaClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function createPayment(Order $order): Payment
    {
        $this->guard();

        $receipt = new ReceiptBuilder($order);
        $items = $this->items($receipt->build()['items']);
        $amount = $receipt->total();

        $payment = $order->payments()->create([
            'gateway' => PaymentSettings::TOCHKA,
            'idempotence_key' => (string) Str::uuid(),
            'status' => Payment::STATUS_PENDING,
            'amount' => $amount,
        ]);

        $data = array_filter([
            'customerCode' => PaymentSettings::customerCode(),
            'merchantId' => PaymentSettings::merchantId(),
            'amount' => round($amount / 100, 2),
            'purpose' => mb_substr('Оплата заказа '.$order->number, 0, 140),
            'paymentMode' => PaymentSettings::paymentModes(),
            'redirectUrl' => $order->accessUrl(),
            'failRedirectUrl' => $order->accessUrl(),
            'ttl' => $this->ttl(),
            // Номер заказа покупатель увидит на странице оплаты; он должен быть
            // уникален для каждой ссылки — повторная попытка оплаты получит свой.
            'paymentLinkId' => mb_substr($order->number.'-'.$payment->id, 0, 45),
            'taxSystemCode' => PaymentSettings::taxSystem(),
            'Client' => array_filter([
                'name' => mb_substr((string) $order->customer_name, 0, 120) ?: null,
                'email' => $order->customer_email,
                'phone' => Phone::format($order->customer_phone),
            ]),
            'Items' => $items,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $answer = $this->client->createPayment($data);
        } catch (Throwable $exception) {
            // Запись оставляем: по ней видно, что попытка была. Повтора нет —
            // у Точки нет ключа повтора, и второй запрос дал бы вторую ссылку.
            $payment->update([
                'status' => Payment::STATUS_CANCELED,
                'payload' => ['error' => mb_substr($exception->getMessage(), 0, 500)],
                'cancellation_reason' => 'Точка не создала платёж',
                'cancelled_at' => now(),
            ]);
            Log::error('Точка не создала платёж.', ['order' => $order->number, 'error' => mb_substr($exception->getMessage(), 0, 300)]);

            throw new RuntimeException('Платёж создать не удалось: '.$exception->getMessage(), 0, $exception);
        }

        if (blank($answer['operationId'] ?? null) || blank($answer['paymentLink'] ?? null)) {
            $payment->update(['status' => Payment::STATUS_CANCELED, 'payload' => $answer, 'cancelled_at' => now(),
                'cancellation_reason' => 'Точка не вернула ссылку на оплату']);

            throw new RuntimeException('Точка не вернула ссылку на оплату.');
        }

        return $this->apply($payment, $answer + ['ttl' => $data['ttl']]);
    }

    public function refreshStatus(Payment $payment): Payment
    {
        if (! $payment->external_id || ! $this->isConfigured()) {
            return $payment;
        }

        try {
            $operation = $this->client->payment($payment->external_id);
        } catch (Throwable $exception) {
            Log::warning('Не удалось узнать у Точки статус платежа.', ['payment' => $payment->id, 'error' => mb_substr($exception->getMessage(), 0, 300)]);

            return $payment;
        }

        return $this->apply($payment, $operation);
    }

    /** Ссылку Точки отменить нельзя — она сама истекает по ttl. */
    public function cancelPayment(Payment $payment): Payment
    {
        return $payment;
    }

    /**
     * Перенести ответ Точки в запись платежа.
     *
     * Оплаченный платёж обратно в «ожидает» не возвращается: возврат денег
     * Точка показывает статусом самого платежа (REFUNDED), а у нас он
     * учитывается отдельно — записью возврата у заказа.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(Payment $payment, array $data): Payment
    {
        $remote = strtoupper((string) ($data['status'] ?? ''));
        $ttl = (int) ($data['ttl'] ?? $payment->payload['ttl'] ?? $this->ttl());

        $status = match (true) {
            in_array($remote, self::PAID, true) => Payment::STATUS_SUCCEEDED,
            $remote === 'AUTHORIZED' => Payment::STATUS_WAITING_FOR_CAPTURE,
            $remote === 'EXPIRED' => Payment::STATUS_CANCELED,
            $payment->status === Payment::STATUS_SUCCEEDED => Payment::STATUS_SUCCEEDED,
            // Ссылка давно истекла, а Точка всё ещё пишет «создан» — считаем
            // неоплаченной, иначе резерв товара висел бы вечно. Если деньги
            // всё-таки придут, вебхук и опрос снова сделают платёж оплаченным.
            $payment->created_at !== null && $payment->created_at->copy()->addMinutes($ttl + 30)->isPast() => Payment::STATUS_CANCELED,
            default => Payment::STATUS_PENDING,
        };

        $payment->fill([
            'external_id' => $data['operationId'] ?? $payment->external_id,
            'status' => $status,
            'confirmation_url' => $data['paymentLink'] ?? $payment->confirmation_url,
            'payment_method' => $data['paymentType'] ?? $payment->payment_method,
            'payload' => $data + ['ttl' => $ttl],
        ]);

        if ($status === Payment::STATUS_SUCCEEDED && ! $payment->paid_at) {
            $payment->paid_at = $this->time($data['paidAt'] ?? null) ?? now();
        }

        if ($status === Payment::STATUS_CANCELED && ! $payment->cancelled_at) {
            $payment->cancelled_at = now();
            $payment->cancellation_reason = $remote === 'EXPIRED' ? 'Срок ссылки на оплату истёк' : 'Ссылка на оплату не оплачена в срок';
        }

        $payment->save();

        return $payment;
    }

    /**
     * Позиции чека в формате Точки — из тех же строк, что считает ReceiptBuilder:
     * сумма платежа и сумма позиций сходятся до копейки.
     *
     * @param  list<array<string, mixed>>  $items  строки ReceiptBuilder::build()
     * @return list<array<string, mixed>>
     */
    public function items(array $items): array
    {
        return array_map(fn (array $item): array => [
            'name' => mb_substr((string) $item['description'], 0, 128),
            'amount' => round((float) $item['amount']['value'], 2),
            'quantity' => (int) $item['quantity'],
            'vatType' => self::vatType((int) $item['vat_code']),
            'paymentMethod' => ($item['payment_mode'] ?? 'full_prepayment') === 'full_payment' ? 'full_payment' : 'full_prepayment',
            'paymentObject' => ($item['payment_subject'] ?? '') === 'service' ? 'service' : 'goods',
            'measure' => 'шт.',
        ], $items);
    }

    public static function vatType(int $code): string
    {
        if (isset(self::VAT_TYPES[$code])) {
            return self::VAT_TYPES[$code];
        }

        throw new RuntimeException($code === 4
            ? 'Ставка НДС 20 % с 2026 года не действует, и Точка её не принимает. Укажите действующую ставку в настройках сайта.'
            : 'Для кода ставки НДС '.$code.' нет соответствия в чеке Точки. Проверьте ставку в настройках сайта.');
    }

    /**
     * Сколько живёт ссылка: на полчаса меньше резерва товара. Тогда ссылка
     * гаснет раньше, чем заказ отменяется, и оплатить отменённый заказ нельзя.
     */
    public function ttl(): int
    {
        $reserve = max(1, (int) config('livsi.order_reserve_minutes', 1440));

        return max(15, min(44640, $reserve - 30));
    }

    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function guard(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Не заданы ключ и код компании Точки — «Интеграции оплаты» в админке.');
        }

        if (! Vat::isConfigured()) {
            throw new RuntimeException('Не определена ставка НДС — чек сформировать нельзя. Задайте её в настройках сайта.');
        }
    }
}
