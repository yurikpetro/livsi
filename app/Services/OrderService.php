<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PromoCode;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Models\User;
use App\Payments\Vat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Создание заказа из корзины.
 *
 * Позиции сохраняются снимком: артикул, название, цена и ставка НДС
 * на момент покупки. Ссылки на вариант мало — цена меняется, вариант
 * могут выключить, а в заказе и в чеке должно остаться то, что человек
 * действительно купил.
 *
 * Подарок оформляется скидкой, распределённой по оплачиваемым позициям,
 * а не позицией с нулевой ценой: нулевая строка невалидна в чеке,
 * а безвозмездная передача — объект НДС (docs/06-scope-v2.md § 2.4).
 */
class OrderService
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly PromoCodeService $promoCodes,
    ) {}

    /** @param  ?string  $promoCode  промокод покупателя — проверяется ещё раз под блокировкой */
    public function createFromCart(Cart $cart, array $customer, ?string $promoCode = null): Order
    {
        $items = $cart->paidItems();

        if ($items->isEmpty()) {
            throw new RuntimeException('Корзина пуста.');
        }

        return DB::transaction(function () use ($cart, $customer, $items, $promoCode) {
            $gift = $cart->giftItem();

            // Подарок действителен, только если сумма всё ещё выше порога:
            // между добавлением подарка и оформлением человек мог убрать товар.
            $subtotal = $cart->subtotal();
            $giftValid = $gift && $this->promo->isGiftUnlocked($subtotal);
            $discount = $giftValid ? (int) $gift->variant->price : 0;

            // Скидка не может быть больше самой корзины.
            $discount = min($discount, $subtotal);

            // Промокод: строку кода блокируем, чтобы два заказа одновременно
            // не потратили одноразовый код дважды.
            $promo = null;
            if (filled($promoCode)) {
                PromoCode::query()->where('code', PromoCodeService::normalize($promoCode))->lockForUpdate()->first();
                $promo = $this->promoCodes->check($promoCode, $subtotal - $discount, (string) ($customer['customer_email'] ?? ''),
                    $customer['customer_phone'] ?? null, isset($customer['user_id']) ? User::find($customer['user_id']) : null);
                $discount += $promo['discount'];
                if ($promo['free_delivery']) {
                    $customer['delivery_price'] = 0;
                }
                $customer += ['promo_code_id' => $promo['promo']->id, 'promo_code' => $promo['promo']->code,
                    'promo_discount' => $promo['discount'], 'promo_free_delivery' => $promo['free_delivery']];
            }

            $order = Order::create(array_merge($customer, [
                // Настоящий номер проставляется сразу после вставки: он
                // выводится из id, которого до неё ещё нет. Заглушка
                // случайная, потому что на колонке уникальный индекс
                // и два одновременных заказа не должны столкнуться.
                'number' => 'tmp-'.Str::random(24),
                'items_total' => $subtotal,
                'discount_total' => $discount,
                'total' => $subtotal - $discount + (int) ($customer['delivery_price'] ?? 0),
                'gift_variant_id' => $giftValid ? $gift->product_variant_id : null,
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'access_token' => Str::random(48),
            ]));

            $order->forceFill(['number' => $this->numberFor($order->id)])->save();

            $shares = $this->spread($discount, $items->map(fn (CartItem $i) => $i->lineTotal())->values()->all());

            foreach ($items->values() as $index => $item) {
                $this->addItem($order, $item, $shares[$index] ?? 0);
            }

            // Подарок остаётся отдельной строкой с нулевой суммой: он уезжает
            // покупателю, значит должен списаться со склада и быть виден
            // менеджеру. В чек эта строка не попадает — там она учтена
            // скидкой по остальным позициям.
            if ($giftValid) {
                $this->addItem($order, $gift, 0, isGift: true);
            }

            $this->reserve($order);

            return $order->load('items');
        });
    }

    private function addItem(Order $order, CartItem $item, int $discount, bool $isGift = false): OrderItem
    {
        $variant = $item->variant;
        $product = $variant->product;
        $lineTotal = $isGift ? 0 : $item->lineTotal();

        return $order->items()->create([
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'title' => $product->title,
            'option_label' => $variant->storefrontLabel(),
            'unit_price' => $isGift ? 0 : $variant->price,
            'quantity' => $item->qty,
            'discount' => $discount,
            'total' => max(0, $lineTotal - $discount),
            'vat_rate' => $product->vat_rate ?? Vat::defaultRate(),
            'is_gift' => $isGift,
        ]);
    }

    /**
     * Распределение скидки по позициям пропорционально их сумме.
     *
     * Остаток от деления отдаётся самым дорогим позициям по копейке,
     * поэтому сумма долей всегда в точности равна скидке. Округление
     * каждой доли по отдельности этого не гарантирует, а чек, который
     * не сходится на копейку, провайдер отклонит.
     *
     * @param  array<int, int>  $totals
     * @return array<int, int>
     */
    public function spread(int $discount, array $totals): array
    {
        $sum = array_sum($totals);

        if ($discount < 1 || $sum < 1) {
            return array_fill(0, count($totals), 0);
        }

        $shares = [];

        foreach ($totals as $index => $total) {
            $shares[$index] = intdiv($discount * $total, $sum);
        }

        $rest = $discount - array_sum($shares);

        // Кому достанутся лишние копейки: сначала самым крупным позициям —
        // так относительная погрешность наименьшая.
        $order = array_keys($totals);
        usort($order, fn ($a, $b) => $totals[$b] <=> $totals[$a]);

        foreach ($order as $index) {
            if ($rest < 1) {
                break;
            }

            // Доля не может превысить саму позицию.
            if ($shares[$index] < $totals[$index]) {
                $shares[$index]++;
                $rest--;
            }
        }

        return $shares;
    }

    /**
     * Резерв квоты под заказ.
     *
     * Резерв ставится сразу, а не после оплаты: иначе два человека оплатят
     * последнюю единицу. Снимается он при отмене или истечении платежа.
     */
    private function reserve(Order $order): void
    {
        foreach ($order->items as $item) {
            $quota = StockQuota::where('product_variant_id', $item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if (! $quota) {
                continue;
            }

            if ($quota->available() < $item->quantity) {
                throw new RuntimeException("Товара «{$item->label()}» осталось меньше, чем в заказе.");
            }

            $quota->increment('reserved', $item->quantity);

            StockQuotaMovement::create([
                'stock_quota_id' => $quota->id,
                'type' => 'reserve',
                'qty' => $item->quantity,
                'comment' => 'Заказ '.$order->number,
            ]);
        }
    }

    /**
     * Номер вида LV-000123: человеку его диктуют по телефону.
     *
     * Выводится из id, а не из `max(id) + 1`. Прежний вариант брал максимум
     * под `lockForUpdate`, и PostgreSQL такой запрос отклоняет: `FOR UPDATE`
     * нельзя сочетать с агрегатной функцией. На SQLite, где идёт разработка,
     * блокировка игнорируется, поэтому ошибка вылезла только на боевом.
     *
     * Блокировать строку вместо агрегата тоже не выход: пока один заказ
     * держит последнюю строку, второй дождётся её, прочитает тот же id
     * и упрётся в уникальный индекс. Идентификатор выдаёт последовательность
     * базы — она атомарна, и гонки не остаётся вовсе.
     *
     * Плата за это — возможные пропуски в нумерации: откатившаяся транзакция
     * забирает значение последовательности с собой. Для номера, который
     * диктуют по телефону, это безразлично.
     */
    private function numberFor(int $id): string
    {
        return 'LV-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
