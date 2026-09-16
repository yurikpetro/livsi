<?php

namespace App\Payments;

use App\Support\Phone;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Чек по 54-ФЗ для ЮKassa.
 *
 * Две вещи, из-за которых это отдельный класс, а не пара строк в шлюзе:
 *
 * 1. Сумма позиций чека обязана в точности совпадать с суммой платежа.
 *    Скидка за подарок распределяется по позициям, и остаток от деления
 *    нельзя просто округлить — копейки должны сойтись до нуля.
 *
 * 2. В чеке у позиции указывается цена за единицу, а не сумма строки.
 *    Если после скидки сумма строки не делится на количество нацело,
 *    строка разбивается на две: несколько единиц по одной цене и одна
 *    по другой. Иначе чек не сойдётся ни в одну, ни в другую сторону.
 */
final class ReceiptBuilder
{
    public function __construct(private readonly Order $order)
    {
    }

    public function build(): array
    {
        $items = [];

        foreach ($this->order->items as $item) {
            foreach ($this->lines($item) as $line) {
                $items[] = $line;
            }
        }

        if ($this->order->delivery_price > 0) {
            $items[] = $this->line(
                'Доставка',
                $this->order->delivery_price,
                1,
                Vat::code(Vat::defaultRate()),
                'service',
            );
        }

        return [
            'customer' => array_filter([
                'email' => $this->order->customer_email,
                'phone' => $this->phone(),
            ]),
            'items' => $items,
        ];
    }

    /**
     * Сумма всех позиций чека — она же сумма платежа.
     *
     * Считается по тем же строкам, что уходят в чек, а не отдельно:
     * два независимых подсчёта рано или поздно разойдутся.
     */
    public function total(): int
    {
        $total = 0;

        foreach ($this->build()['items'] as $item) {
            $total += (int) round(((float) $item['amount']['value']) * 100) * (int) $item['quantity'];
        }

        return $total;
    }

    /**
     * Позиции чека для одной строки заказа.
     *
     * @return array<int, array>
     */
    private function lines(OrderItem $item): array
    {
        $vatCode = Vat::code($item->vat_rate);
        $total   = $item->total;
        $qty     = $item->quantity;

        if ($qty < 1 || $total < 1) {
            return [];
        }

        $perUnit  = intdiv($total, $qty);
        $remainder = $total - $perUnit * $qty;

        if ($remainder === 0) {
            return [$this->line($item->label(), $perUnit, $qty, $vatCode)];
        }

        // Остаток отдаём одной единице: сумма строки при этом сходится
        // копейка в копейку, а покупатель видит понятные две позиции.
        return [
            $this->line($item->label(), $perUnit, $qty - 1, $vatCode),
            $this->line($item->label(), $perUnit + $remainder, 1, $vatCode),
        ];
    }

    private function line(string $description, int $unitPrice, int $quantity, int $vatCode, string $subject = 'commodity'): array
    {
        return [
            // 128 символов — ограничение чека.
            'description' => mb_substr($description, 0, 128),
            'quantity'    => (string) $quantity,
            'amount'      => [
                'value'    => number_format($unitPrice / 100, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'vat_code'       => $vatCode,
            'payment_mode'   => 'full_prepayment',
            'payment_subject' => $subject,
        ];
    }

    /** Телефон в чек — только в формате ITU-T E.164, иначе провайдер его отклонит. */
    private function phone(): ?string
    {
        return Phone::digits($this->order->customer_phone);
    }
}
