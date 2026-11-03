@php
    use App\Services\CartService;
    use App\Support\Money;

    $variant = $product?->variants->where('is_active', true)->firstWhere('id', $variantId) ?? $product?->storefrontVariant();
    $photos = $product?->images->unique('path')->values() ?? collect();
    $canAdd = $variantId && $variantId === $variant?->id && $variant->available() > 0;
    $quantity = $variant ? (app(CartService::class)->currentOrNull()?->items->first(
        fn ($item) => $item->product_variant_id === $variant->id && ! $item->is_gift
    )?->qty ?? 0) : 0;
@endphp

<div>
    @teleport('body')
        <div x-data
             x-show="$wire.open"
             x-trap.inert.noscroll="$wire.open"
             x-cloak
             x-on:keydown.escape.window="$wire.close()"
             wire:click.self="close"
             class="product-modal-shell"
             role="dialog"
             aria-modal="true"
             aria-label="Быстрый просмотр товара">
            @if ($product)
                <div x-show="$wire.open"
                     x-transition:enter="transition duration-[380ms] ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                     x-transition:enter-start="translate-y-6 opacity-0 scale-[0.98]"
                     x-transition:enter-end="translate-y-0 opacity-100 scale-100"
                     class="product-modal" wire:key="quick-view-product-{{ $product->id }}">
                    <button type="button" wire:click="close" class="product-modal-close" aria-label="Закрыть быстрый просмотр">×</button>

                    <div class="product-modal-photo" x-data="{ photo: 0 }">
                        @foreach ($photos as $photo)
                            <div x-show="photo === {{ $loop->index }}" @if (! $loop->first) x-cloak @endif>
                                <x-img :path="$photo->path" :alt="$photo->alt" sizes="(min-width: 701px) 580px, 100vw" />
                            </div>
                        @endforeach
                        @if ($photos->count() > 1)
                            <button class="gallery-prev" type="button" x-on:click="photo = (photo + {{ $photos->count() - 1 }}) % {{ $photos->count() }}" aria-label="Предыдущее фото">‹</button>
                            <button class="gallery-next" type="button" x-on:click="photo = (photo + 1) % {{ $photos->count() }}" aria-label="Следующее фото">›</button>
                            <div class="gallery-thumbs" aria-label="Фотографии товара">
                                @foreach ($photos as $photo)
                                    <button type="button" x-on:click="photo = {{ $loop->index }}" x-bind:aria-pressed="photo === {{ $loop->index }}" aria-label="Показать фото {{ $loop->iteration }}"><img src="{{ asset($photo->path) }}" alt="" loading="lazy"></button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="product-modal-copy">
                        <div class="modal-kicker"><span>{{ $product->line?->title ?? ($product->is_pro ? 'PRO' : 'LIVSI') }}</span></div>
                        <h2>{{ $product->title }}</h2>
                        <p class="modal-benefit">{{ $product->short_description }}</p>

                        @if ($product->variants->isNotEmpty())
                            <div class="modal-choice">
                                <span>Объём</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($product->variants as $option)
                                        <button type="button" wire:click="selectVariant({{ $option->id }})" wire:key="qv-variant-{{ $option->id }}"
                                                @disabled(! $option->is_active || $option->available() < 1)
                                                @class(['active' => $variantId === $option->id]) aria-pressed="{{ $variantId === $option->id ? 'true' : 'false' }}">
                                            {{ $option->option_volume ?: $option->storefrontLabel() }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="modal-facts">
                            @foreach (['Аромат' => $variant?->option_aroma ?: $product->aroma, 'Эффект' => $product->effect] as $label => $value)
                                @if ($value)
                                    <p><span>{{ $label }}</span><b>{{ $value }}</b></p>
                                @endif
                            @endforeach
                        </div>

                        <div class="modal-buy">
                            <strong>{{ Money::rub($variant?->price) }}</strong>
                            <div data-cart-card data-variant-id="{{ $variant?->id }}" data-quantity="{{ $quantity }}" class="product-cart-control" wire:key="qv-cart-{{ $variant?->id }}">
                                <form wire:submit="addToCart" @if ($quantity > 0) hidden @endif>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="addToCart" @disabled(! $canAdd) @unless ($canAdd) data-cart-unavailable @endunless>
                                        {{ $canAdd ? 'Добавить в корзину' : 'Нет в наличии' }} <b aria-hidden="true">＋</b>
                                    </button>
                                </form>
                                <div class="product-quantity-control modal-quantity" data-cart-quantity @if ($quantity < 1) hidden @endif role="group" aria-label="Количество товара {{ $product->title }}">
                                    <button type="button" data-cart-step="decrementVariant" aria-label="Уменьшить количество">−</button>
                                    <b data-cart-count aria-live="polite">{{ $quantity }}</b>
                                    <button type="button" data-cart-step="incrementVariant" aria-label="Увеличить количество">+</button>
                                </div>
                            </div>
                        </div>

                        <div class="modal-details">
                            @if (filled($product->active_ingredients))
                                <section><h3>Описание</h3><p class="whitespace-pre-line">{{ $product->active_ingredients }}</p></section>
                            @endif
                            @foreach (['Состав' => $product->composition, 'Применение' => $product->application] as $label => $text)
                                <section><h3>{{ $label }}</h3><p>{{ $text ?: 'Информация уточняется. Следуйте инструкции на упаковке.' }}</p></section>
                            @endforeach
                        </div>

                        @if ($recommendations->isNotEmpty())
                            <section class="modal-recommendations" aria-label="С этим товаром рекомендуем">
                                <h3>С этим товаром рекомендуем</h3>
                                <div>
                                    @foreach ($recommendations as $related)
                                        <button type="button" wire:click="show({{ $related->id }})" aria-label="Открыть карточку товара {{ $related->title }}">
                                            @if ($related->primaryImage())<img src="{{ asset($related->primaryImage()->path) }}" alt="" loading="lazy">@else<span class="modal-related-placeholder" aria-hidden="true"></span>@endif
                                            <span><small>{{ $related->line?->title ?? 'PRO' }}</small><b>{{ $related->title }}</b><strong>{{ Money::rub($related->priceFrom()) }}</strong></span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
