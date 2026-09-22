<?php

namespace App\Services;

use App\Mail\OrderCancelled;
use App\Mail\OrderPaid;
use App\Mail\OrderPaidManagerNotice;
use App\Mail\OrderPlaced;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Письма по заказу.
 *
 * Собраны в одном месте, потому что поводов для письма три — оформление,
 * оплата и отмена, — а мест, откуда они наступают, больше: чекаут, вебхук
 * провайдера, опрос статуса со страницы заказа и действия менеджера
 * в админке. Разложить `Mail::to()` по этим местам значит однажды забыть
 * про одно из них.
 *
 * Письмо никогда не ломает то, ради чего вызвано. Заказ уже создан, деньги
 * уже приняты — и падение почтового сервера не должно превращаться
 * в ошибку оплаты или в повторный вебхук. Поэтому всё в try/catch,
 * а сбой уходит в лог.
 */
class OrderMailer
{
    /** Заказ оформлен, оплата впереди. */
    public function placed(Order $order): void
    {
        $this->toCustomer($order, new OrderPlaced($order), 'placed');
    }

    /**
     * Оплата прошла: письмо покупателю и уведомление менеджеру.
     *
     * Вызывается только на самом переходе в «оплачен», а не при каждом
     * обращении: вебхук провайдера приходит повторно, и без этого человек
     * получил бы несколько одинаковых писем.
     */
    public function paid(Order $order): void
    {
        $this->toCustomer($order, new OrderPaid($order), 'paid');
        $this->toManager($order, new OrderPaidManagerNotice($order));
    }

    public function cancelled(Order $order): void
    {
        $this->toCustomer($order, new OrderCancelled($order), 'cancelled');
    }

    private function toCustomer(Order $order, Mailable $letter, string $reason): void
    {
        if (blank($order->customer_email)) {
            return;
        }

        $this->queue($order->customer_email, $letter, ['order' => $order->number, 'reason' => $reason]);
    }

    /**
     * Адрес менеджера правится в настройках сайта, значение из окружения —
     * запасное. Не задан — письмо не уходит, но заказ от этого не страдает.
     */
    private function toManager(Order $order, Mailable $letter): void
    {
        $to = Setting::get('manager_email') ?: config('livsi.manager_email');

        if (blank($to)) {
            Log::warning('Заказ оплачен, но менеджеру не написали: не задан адрес.', [
                'order' => $order->number,
            ]);

            return;
        }

        $this->queue($to, $letter, ['order' => $order->number, 'reason' => 'manager']);
    }

    /** @param array<string, mixed> $context */
    private function queue(string $to, Mailable $letter, array $context): void
    {
        try {
            Mail::to($to)->queue($letter);
        } catch (Throwable $e) {
            Log::error('Письмо по заказу не поставлено в очередь.', $context + [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
