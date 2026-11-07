<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Phone;

/**
 * Чек по 54-ФЗ. Строки — в формате ЮKassa; для Точки и CloudKassir их
 * переводят TochkaGateway::items() и CloudKassir::body() — суммы те же.
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
    public function __construct(private readonly Order $order) {}

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
        $total = $item->total;
        $qty = $item->quantity;

        if ($qty < 1 || $total < 1) {
            return [];
        }

        $perUnit = intdiv($total, $qty);
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

    /**
     * Второй чек «полный расчёт» (зачёт предоплаты): те же суммы по штукам,
     * что и в первом чеке, признак full_payment. Штука с отсканированным КИЗ —
     * отдельной строкой «маркированный товар» со своим кодом (mark_code_info):
     * по нему Честный знак выводит код из оборота. Штуки без кода — обычным
     * товаром (о них предупреждает ReceiptService).
     *
     * Код уходит как отсканирован — целиком, с крипто-хвостом и разделителем
     * GS (в JSON — \u001d), без base64: так требует ЮKassa (ответ поддержки).
     *
     * @return list<array<string, mixed>>
     */
    public function closingItems(): array
    {
        $this->order->loadMissing('items.marks');
        $items = [];
        foreach ($this->order->items as $item) {
            $qty = (int) $item->quantity;
            $total = (int) $item->total;
            if ($qty < 1 || $total < 1) {
                continue;
            }
            $vatCode = Vat::code($item->vat_rate);
            // Цены по штукам ровно как в первом чеке: остаток — последней штуке.
            $per = intdiv($total, $qty);
            $prices = array_fill(0, $qty, $per);
            $prices[$qty - 1] += $total - $per * $qty;
            $codes = $item->marks->sortBy('id')->pluck('code')->values()->all();
            $plain = [];
            foreach ($prices as $i => $price) {
                if (isset($codes[$i]) && is_string($codes[$i]) && $codes[$i] !== '') {
                    $items[] = $this->line($item->label(), $price, 1, $vatCode, 'marked', 'full_payment')
                        + ['measure' => 'piece', 'mark_mode' => 0, 'mark_code_info' => ['gs_1m' => $codes[$i]]];
                } else {
                    $plain[$price] = ($plain[$price] ?? 0) + 1;
                }
            }
            foreach ($plain as $price => $count) {
                $items[] = $this->line($item->label(), (int) $price, $count, $vatCode, 'commodity', 'full_payment') + ['measure' => 'piece'];
            }
        }
        if ($this->order->delivery_price > 0) {
            $items[] = $this->line('Доставка', $this->order->delivery_price, 1, Vat::code(Vat::defaultRate()), 'service', 'full_payment')
                + ['measure' => 'piece'];
        }

        return $items;
    }

    /** Сумма строк чека в копейках. @param list<array<string, mixed>> $items */
    public static function sum(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) round(((float) $item['amount']['value']) * 100) * (int) $item['quantity'];
        }

        return $total;
    }

    /** Покупатель для чека: почта (Чеки от ЮKassa шлют только на почту) и телефон. */
    public function customer(): array
    {
        return array_filter(['email' => $this->order->customer_email, 'phone' => $this->phone()]);
    }

    private function line(string $description, int $unitPrice, int $quantity, int $vatCode, string $subject = 'commodity', string $mode = 'full_prepayment'): array
    {
        return [
            // 128 символов — ограничение чека.
            'description' => mb_substr($description, 0, 128),
            'quantity' => (string) $quantity,
            'amount' => [
                'value' => number_format($unitPrice / 100, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'vat_code' => $vatCode,
            'payment_mode' => $mode,
            'payment_subject' => $subject,
        ];
    }

    /** Телефон в чек — только в формате ITU-T E.164, иначе провайдер его отклонит. */
    private function phone(): ?string
    {
        return Phone::digits($this->order->customer_phone);
    }
}
