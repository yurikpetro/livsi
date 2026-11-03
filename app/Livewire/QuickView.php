<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Быстрый просмотр товара из листинга — как в макете.
 *
 * Галерея, выбор объёма, корзина и подробности доступны в одном окне.
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

        $variant = $product->storefrontVariant();
        $this->variantId = $variant && $variant->available() > 0 ? $variant->id : null;

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function selectVariant(int $variantId): void
    {
        $product = $this->product();

        $variant = $product?->variants->firstWhere('id', $variantId);

        if ($variant?->is_active && $variant->available() > 0) {
            $this->variantId = $variantId;
        }
    }

    /**
     * Окно остаётся открытым; результат добавления сообщает корзина.
     */
    public function addToCart(): void
    {
        $variant = $this->product()?->variants->firstWhere('id', $this->variantId);

        if (! $variant?->is_active || $variant->available() < 1) {
            $this->dispatch('cart-notice', message: 'Этот вариант сейчас недоступен');

            return;
        }

        $this->dispatch('cart-add', variantId: $this->variantId);
    }

    public function render()
    {
        $product = $this->product();
        $recommendations = $product?->recommendedProducts() ?? collect();

        return view('livewire.quick-view', [
            'product' => $product,
            'recommendations' => $recommendations,
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
