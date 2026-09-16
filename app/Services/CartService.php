<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Гостевая корзина живёт по токену в cookie на 30 дней: регистрация ради
 * первой покупки — прямая потеря конверсии (решение заказчика).
 *
 * Количество ограничено квотой сайта: остатки не синхронизируются с учётной
 * системой, сайт торгует выделенной частью склада (docs/06-scope-v2.md §2.1).
 */
class CartService
{
    public const COOKIE = 'livsi_cart';

    private const COOKIE_DAYS = 30;

    private ?Cart $cart = null;

    /** Токен cookie, к которому привязана мемоизация. */
    private ?string $boundTo = null;

    public function __construct(private readonly PromoService $promo) {}

    /**
     * Существующая корзина или null. Чтение не создаёт строку в базе:
     * иначе каждый заход бота на любую страницу плодил бы пустые корзины.
     */
    public function currentOrNull(): ?Cart
    {
        $token = request()->cookie(self::COOKIE);

        // Мемоизация привязана к токену, а не к времени жизни объекта: под Octane
        // и в тестах один экземпляр сервиса переживает несколько запросов, и без
        // этой проверки второй запрос получил бы чужую корзину.
        if ($this->boundTo === $token && $this->cart !== null) {
            return $this->cart;
        }

        $this->boundTo = $token;

        return $this->cart = $token
            ? Cart::with('items.variant.product', 'items.variant.quota')->where('token', $token)->first()
            : null;
    }

    /** Текущая корзина; создаётся при первом изменении. */
    public function current(): Cart
    {
        if ($cart = $this->currentOrNull()) {
            return $cart;
        }

        $token = Str::random(48);

        $this->cart = Cart::create(['token' => $token, 'last_activity_at' => now()]);
        $this->cart->setRelation('items', collect());

        Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_DAYS * 24 * 60));

        return $this->cart;
    }

    /**
     * Добавляет вариант в корзину. Возвращает фактически добавленное
     * количество: оно может быть меньше запрошенного из-за квоты.
     */
    public function add(ProductVariant $variant, int $qty = 1): int
    {
        $cart = $this->current();
        $qty  = max(1, $qty);

        $item = $cart->items->first(
            fn (CartItem $i) => $i->product_variant_id === $variant->id && ! $i->is_gift
        );

        $current = $item?->qty ?? 0;
        $allowed = $this->cap($variant, $current + $qty);

        if ($allowed <= 0) {
            return 0;
        }

        if ($item) {
            $item->update(['qty' => $allowed]);
        } else {
            $cart->items()->create([
                'product_variant_id' => $variant->id,
                'qty'                => $allowed,
            ]);
        }

        return $this->refresh($cart, $allowed - $current);
    }

    public function setQty(CartItem $item, int $qty): void
    {
        $cart = $this->current();

        if ($qty <= 0) {
            $this->remove($item);

            return;
        }

        $item->update(['qty' => $this->cap($item->variant, $qty)]);

        $this->refresh($cart);
    }

    public function remove(CartItem $item): void
    {
        $cart = $this->current();
        $item->delete();

        $this->refresh($cart);
    }

    /** Выбор подарка покупателем. Доступен только при достигнутом пороге. */
    public function chooseGift(ProductVariant $variant): bool
    {
        $cart = $this->current();
        $rule = $this->promo->giftRule();

        if (! $rule || ! $this->promo->isGiftUnlocked($cart->subtotal())) {
            return false;
        }

        if (! $this->promo->eligibleGifts()->contains('id', $variant->id)) {
            return false;
        }

        $cart->items()->where('is_gift', true)->delete();

        $cart->items()->create([
            'product_variant_id' => $variant->id,
            'qty'                => 1,
            'is_gift'            => true,
            'promo_rule_id'      => $rule->id,
        ]);

        $this->refresh($cart);

        return true;
    }

    /**
     * Пересчёт корзины после любого изменения: если сумма опустилась ниже
     * порога, подарок снимается — иначе его можно было бы «выбить» разово
     * и потом удалить оплачиваемые позиции.
     */
    public function refresh(Cart $cart, int $return = 0): int
    {
        $cart->load('items.variant.product', 'items.variant.quota');

        if ($cart->giftItem() && ! $this->promo->isGiftUnlocked($cart->subtotal())) {
            $cart->items()->where('is_gift', true)->delete();
            $cart->load('items.variant.product', 'items.variant.quota');
        }

        $cart->forceFill(['last_activity_at' => now()])->save();

        $this->cart = $cart;

        return $return;
    }

    /** Ограничение количества доступной квотой сайта. */
    private function cap(ProductVariant $variant, int $qty): int
    {
        return max(0, min($qty, $variant->available()));
    }

    /**
     * Опустошить корзину после оформления заказа.
     *
     * Саму корзину не удаляем: к ней привязана кука, и человек продолжит
     * покупки в той же — незачем плодить записи на каждый заказ.
     */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->setRelation('items', $cart->items()->getRelated()->newCollection());
    }
}
