<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;

/**
 * Платёжный шлюз.
 *
 * Интерфейс заложен с самого начала (docs/03-payments-and-delivery.md § 5):
 * провайдера меняют — из-за тарифов, из-за отказа банка, из-за перехода
 * на юрлицо. Витрина и заказы не должны знать, кто именно принимает деньги.
 */
interface PaymentGateway
{
    /** Создать платёж и вернуть запись со ссылкой на оплату. */
    public function createPayment(Order $order): Payment;

    /** Спросить у провайдера актуальный статус — на случай потерянного вебхука. */
    public function refreshStatus(Payment $payment): Payment;

    /** Настроен ли шлюз: без ключей платежи создавать нельзя. */
    public function isConfigured(): bool;
}
