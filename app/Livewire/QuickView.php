<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\Setting;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Быстрый просмотр товара из листинга — как в макете.
 *
 * Именно «в дополнение», а не «вместо»: страница товара остаётся основной,
 * у неё свой адрес, микроразметка и место в карте сайта. Модалка нужна,
 * чтобы выбрать объём и положить в корзину, не уходя из выдачи.
 */
class QuickView extends Component
{
    public bool $open = false;

    public ?int $productId = null;

    public ?int $variantId = null;

    #[On('quick-view')]
    public function show(int $productId): void
    {
        $product = Product::active()->with('variants.quota')->find($productId);

        if (! $product) {
            return;
        }

        $this->productId = $product->id;

        // Предвыбираем доступный вариант: по умолчанию, а если его нет
        // в наличии — первый доступный.
        $this->variantId = ($product->defaultVariant()?->available() > 0
            ? $product->defaultVariant()
            : $product->variants->first(fn ($v) => $v->available() > 0))?->id;

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function selectVariant(int $variantId): void
    {
        $product = $this->product();

        if ($product && $product->variants->contains('id', $variantId)) {
            $this->variantId = $variantId;
        }
    }

    /**
     * Кладём в корзину и закрываем модалку: иначе поверх быстрого просмотра
     * открылась бы выдвижная корзина, и получилось бы два слоя друг на друге.
     */
    public function addToCart(): void
    {
        if (! $this->variantId) {
            return;
        }

        $this->open = false;

        $this->dispatch('cart-add', variantId: $this->variantId);
    }

    public function render()
    {
        return view('livewire.quick-view', [
            'product' => $this->product(),
            // Порог бесплатной доставки заказчик меняет в настройках,
            // поэтому в модалке он читается, а не вписан в шаблон.
            'freeShipping' => (int) Setting::get('free_shipping_threshold', 0),
        ]);
    }

    private function product(): ?Product
    {
        if (! $this->productId) {
            return null;
        }

        return Product::active()
            ->with(['line', 'images', 'variants.quota', 'purposes', 'tasks'])
            ->find($this->productId);
    }
}
