<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\PromoService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly PromoService $promo,
    ) {}

    public function index()
    {
        // Просмотр корзины её не создаёт — только добавление товара.
        $cart = $this->cart->currentOrNull() ?? tap(new Cart(), fn (Cart $c) => $c->setRelation('items', collect()));

        $subtotal = $cart->subtotal();

        return view('cart.index', [
            'cart'                    => $cart,
            'subtotal'                => $subtotal,
            'remainingToFreeShipping' => $this->promo->remainingToFreeShipping($subtotal),
            'remainingToGift'         => $this->promo->remainingToGift($subtotal),
            'giftUnlocked'            => $this->promo->isGiftUnlocked($subtotal),
            'eligibleGifts'           => $this->promo->eligibleGifts(),
        ]);
    }

    public function add(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'qty'        => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $variant = ProductVariant::with('quota')->findOrFail($data['variant_id']);
        $added   = $this->cart->add($variant, $data['qty'] ?? 1);

        return $added > 0
            ? back()->with('cart_status', 'Добавили в корзину')
            : back()->with('cart_error', 'Этого товара сейчас нет в наличии');
    }

    public function update(Request $request, CartItem $item)
    {
        $this->authorizeItem($item);

        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:99']]);

        $this->cart->setQty($item, $data['qty']);

        return back();
    }

    public function destroy(CartItem $item)
    {
        $this->authorizeItem($item);

        $this->cart->remove($item);

        return back()->with('cart_status', 'Товар удалён из корзины');
    }

    public function chooseGift(Request $request)
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
        ]);

        $ok = $this->cart->chooseGift(ProductVariant::findOrFail($data['variant_id']));

        return $ok
            ? back()->with('cart_status', 'Подарок добавлен к заказу')
            : back()->with('cart_error', 'Этот подарок сейчас недоступен');
    }

    /** Чужую позицию корзины трогать нельзя. */
    private function authorizeItem(CartItem $item): void
    {
        abort_unless($item->cart_id === $this->cart->currentOrNull()?->id, 403);
    }
}