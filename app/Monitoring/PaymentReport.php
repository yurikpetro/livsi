<?php

namespace App\Monitoring;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Payment;
use App\Support\Money;

class PaymentReport
{
    public function toHtml(Payment $payment): string
    {
        $order = $payment->order;
        $test = $payment->payload['test'] ?? null;
        $heading = match ($test) {
            true => '🧪 Тестовая оплата',
            false => '✅ Оплата получена',
            default => '✅ Оплата получена (режим не указан провайдером)',
        };
        $method = match ($payment->payment_method) {
            'sbp' => 'СБП',
            'bank_card' => 'Банковская карта',
            default => $payment->payment_method ?: 'Не указан',
        };
        $url = htmlspecialchars(OrderResource::getUrl('edit', ['record' => $order], panel: 'admin'), ENT_QUOTES, 'UTF-8');

        return implode("\n", [
            '<b>'.ErrorReport::escape($heading).'</b>',
            ErrorReport::escape('Заказ: '.$order->number),
            ErrorReport::escape('Сумма: '.($payment->currency === 'RUB'
                ? Money::rub($payment->amount)
                : number_format($payment->amount / 100, 2, '.', ' ').' '.$payment->currency)),
            ErrorReport::escape('Способ: '.$method),
            ErrorReport::escape('Платёж: '.$payment->external_id),
            ErrorReport::escape(($payment->paid_at ?? $order->paid_at)->timezone('Europe/Moscow')->format('d.m.Y H:i:s').' МСК'),
            '',
            '<a href="'.$url.'">Открыть заказ в админке</a>',
        ]);
    }
}
