@props(['product'])

@php
    use App\Support\Money;

    $variant   = $product->storefrontVariant();
    $image     = $product->primaryImage();
    $available = $product->isAvailable();
    $cartItem  = $variant ? app(\App\Services\CartService::class)->currentOrNull()?->items->first(
        fn ($item) => $item->product_variant_id === $variant->id && ! $item->is_gift
    ) : null;
@endphp

<article {{ $attributes->class(['product-card-lift storefront-card group relative flex flex-col bg-paper']) }}>
    <div class="relative aspect-[4/5] overflow-hidden bg-shell">
        {{-- Вся фотография — кнопка быстрого просмотра, как в прототипе:
             при наведении по центру выезжает подпись, снимок приближается.
             Страница товара открывается по заголовку, поэтому два действия
             не спорят за один клик. --}}
        <button type="button"
                x-data
                x-on:click="Livewire.dispatch('quick-view', { productId: {{ $product->id }} })"
                class="product-open"
                aria-label="Быстрый просмотр: {{ $product->title }}">
            @if ($image)
                <x-img :path="$image->path" :alt="$image->alt"
                       sizes="(min-width: 1024px) 25vw, 50vw"
                       class="product-shot" />
            @endif

            <i>Быстрый просмотр</i>
        </button>

        @if (in_array($product->badge, ['new', 'best'], true))
            <span style="background: {{ $product->line?->color_bg ?? '#a8d8ef' }}" class="pointer-events-none absolute left-3 top-3 z-[2] px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]
                ">
                {{ ['new' => 'Новинка', 'best' => 'Бестселлер'][$product->badge] }}
            </span>
        @endif

        @unless ($available)
            <span class="pointer-events-none absolute bottom-3 left-3 z-[2] bg-paper px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]">
                Нет в наличии
            </span>
        @endunless

    </div>

    <div class="product-card-copy flex flex-1 flex-col">
        <div class="flex items-start justify-between gap-3">
            <span class="product-card-line text-ink">
                {{ $product->line?->title ?? ($product->is_pro ? 'PRO' : '') }}
            </span>


        </div>

        <h3 class="product-card-title mt-2">
            <a href="{{ route('catalog.show', $product) }}" x-data x-on:click.prevent="Livewire.dispatch('quick-view', { productId: {{ $product->id }} })" class="hover:text-green">{{ $product->title }}</a>
        </h3>

        <p class="product-card-benefit mt-1 text-muted">{{ $product->short_description }}</p>

        <div class="product-card-footer">
            @if ($variant)
                <p class="product-card-meta text-muted">{{ $variant->storefrontLabel() }}</p>
            @endif

            <div class="flex items-center justify-between gap-2 border-t border-line">
                <span class="product-card-price">{{ Money::rub($product->priceFrom()) }}</span>

                @if ($available && $variant)
                    {{-- Предвыбираем доступный вариант; объём и аромат
                         можно изменить в быстром просмотре и карточке товара. --}}
                    <div data-cart-card data-variant-id="{{ $variant->id }}" data-quantity="{{ $cartItem?->qty ?? 0 }}" class="product-cart-control">
                        {{-- Обычный POST остаётся доступен без JavaScript. --}}
                        <form method="POST" action="{{ route('cart.add') }}">
                            @csrf
                            <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                            <button type="submit" class="btn btn-outline" aria-label="Добавить {{ $product->title }} в корзину">
                                В корзину <span aria-hidden="true">+</span>
                            </button>
                        </form>
                        <div class="product-quantity-control" data-cart-quantity hidden role="group" aria-label="Количество товара {{ $product->title }}">
                            <button type="button" data-cart-step="decrementVariant" aria-label="Уменьшить количество {{ $product->title }}">−</button>
                            <b data-cart-count aria-live="polite">{{ $cartItem?->qty ?? 0 }}</b>
                            <button type="button" data-cart-step="incrementVariant" aria-label="Увеличить количество {{ $product->title }}">+</button>
                        </div>
                    </div>
                @else
                    <button type="button" disabled
                            class="btn btn-outline opacity-40 cursor-not-allowed">
                        Нет в наличии
                    </button>
                @endif
            </div>
        </div>
    </div>
</article>
