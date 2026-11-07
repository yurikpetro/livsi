<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;

/**
 * Какой шлюз работает сейчас.
 *
 * Новые платежи создаёт тот, кто выбран в «Интеграциях оплаты». Статус
 * старого платежа спрашиваем у того, кто его принимал: заказы, оплаченные
 * через ЮKassa до переключения, продолжают сверяться с ЮKassa.
 */
class GatewayManager implements PaymentGateway
{
    public function __construct(
        private readonly YooKassaGateway $yookassa,
        private readonly TochkaGateway $tochka,
    ) {}

    public function active(): PaymentGateway
    {
        return $this->for(PaymentSettings::provider());
    }

    public function for(?string $gateway): PaymentGateway
    {
        return $gateway === PaymentSettings::TOCHKA ? $this->tochka : $this->yookassa;
    }

    public function createPayment(Order $order): Payment
    {
        return $this->active()->createPayment($order);
    }

    public function refreshStatus(Payment $payment): Payment
    {
        $gateway = $this->for($payment->gateway);

        // Ключи этого провайдера убраны — спросить не у кого; запись остаётся как есть.
        return $gateway->isConfigured() ? $gateway->refreshStatus($payment) : $payment;
    }

    public function cancelPayment(Payment $payment): Payment
    {
        $gateway = $this->for($payment->gateway);

        return $gateway->isConfigured() ? $gateway->cancelPayment($payment) : $payment;
    }

    public function isConfigured(): bool
    {
        return $this->active()->isConfigured();
    }
}
