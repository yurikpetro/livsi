<?php

namespace App\Livewire;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\PromoService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Выдвижная корзина, как в макете: панель справа поверх страницы,
 * прогресс до бесплатной доставки, шаг количества, удаление.
 *
 * Компонент рендерит и кнопку в шапке со счётчиком, и саму панель —
 * иначе счётчик и содержимое разъезжались бы между двумя компонентами.
 *
 * Страница /cart остаётся как запасной вариант для случая, когда JavaScript
 * недоступен: формы на карточках работают и без него.
 */
class CartDrawer extends Component
{
    public bool $open = false;

    private CartService $cart;

    private PromoService $promo;

    public function boot(CartService $cart, PromoService $promo): void
    {
        $this->cart  = $cart;
        $this->promo = $promo;
    }

    public function openDrawer(): void
    {
        $this->open = true;
    }

    public function closeDrawer(): void
    {
        $this->open = false;
    }

    /** Вызывается со страницы товара и из карточки в листинге. */
    #[On('cart-add')]
    public function addToCart(int $variantId, int $qty = 1): void
    {
        $variant = ProductVariant::with('quota')->find($variantId);

        if (! $variant) {
            return;
        }

        $added = $this->cart->add($variant, max(1, $qty));

        $this->open = true;

        if ($added < 1) {
            $this->dispatch('cart-notice', message: 'Этого товара сейчас нет в наличии');
        }
    }

    public function increment(int $itemId): void
    {
        if ($item = $this->ownedItem($itemId)) {
            $this->cart->setQty($item, $item->qty + 1);
        }
    }

    public function decrement(int $itemId): void
    {
        if ($item = $this->ownedItem($itemId)) {
            $this->cart->setQty($item, $item->qty - 1);
        }
    }

    public function removeItem(int $itemId): void
    {
        if ($item = $this->ownedItem($itemId)) {
            $this->cart->remove($item);
        }
    }

    public function chooseGift(int $variantId): void
    {
        $variant = ProductVariant::find($variantId);

        if ($variant && ! $this->cart->chooseGift($variant)) {
            $this->dispatch('cart-notice', message: 'Этот подарок сейчас недоступен');
        }
    }

    public function render()
    {
        $cart     = $this->cart->currentOrNull() ?? $this->emptyCart();
        $subtotal = $cart->subtotal();

        $freeShippingThreshold = $this->promo->freeShippingRule()?->threshold;
        $remaining             = $this->promo->remainingToFreeShipping($subtotal);

        return view('livewire.cart-drawer', [
            'cart'          => $cart,
            'subtotal'      => $subtotal,
            'count'         => $cart->totalQty(),
            'remaining'     => $remaining,
            'progress'      => $freeShippingThreshold > 0
                ? min(100, (int) round($subtotal / $freeShippingThreshold * 100))
                : 100,
            'giftUnlocked'  => $this->promo->isGiftUnlocked($subtotal),
            'remainingGift' => $this->promo->remainingToGift($subtotal),
            'gifts'         => $this->promo->eligibleGifts(),
        ]);
    }

    /** Чужую позицию корзины изменить нельзя. */
    private function ownedItem(int $itemId): ?CartItem
    {
        $cart = $this->cart->currentOrNull();

        if (! $cart) {
            return null;
        }

        return $cart->items->firstWhere('id', $itemId);
    }

    private function emptyCart(): Cart
    {
        $cart = new Cart();
        $cart->setRelation('items', collect());

        return $cart;
    }
}
