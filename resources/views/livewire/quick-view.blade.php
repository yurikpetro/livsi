@php
    use App\Support\Money;
    use App\Support\Plural;

    $variant = $product?->variants->firstWhere('id', $variantId) ?? $product?->defaultVariant();
    $image   = $product?->primaryImage();
@endphp

<div>
    @teleport('body')
        <div x-data
             x-show="$wire.open"
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
                     class="product-modal">

                    <button type="button" wire:click="close" class="product-modal-close"
                            aria-label="Закрыть быстрый просмотр">×</button>

                    <div class="product-modal-photo">
                        @if ($image)
                            <x-img :path="$image->path" :alt="$image->alt"
                                   sizes="(min-width: 768px) 520px, 100vw" />
                        @endif
                    </div>

                    <div class="product-modal-copy">
                        <div class="modal-kicker">
                            <span>{{ $product->line?->title ?? ($product->is_pro ? 'PRO' : 'LIVSI') }}</span>

                            @if ($product->rating)
                                {{-- Источник отзывов называется и здесь: они собраны
                                     не на сайте (docs/07-design-review.md § 8.1). --}}
                                <b>★ {{ number_format($product->rating, 1, ',', '') }} ·
                                    {{ Plural::reviews((int) $product->reviews_count) }} на {{ $product->reviews_source }}</b>
                            @endif
                        </div>

                        <h2>{{ $product->title }}</h2>

                        <p class="modal-benefit">{{ $product->short_description }}</p>

                        @if ($product->variants->count() > 1)
                            <div class="modal-choice">
                                <span>Объём</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($product->variants as $v)
                                        @php $out = $v->available() < 1; @endphp
                                        <button type="button"
                                                wire:click="selectVariant({{ $v->id }})"
                                                wire:key="qv-variant-{{ $v->id }}"
                                                @disabled($out)
                                                @class(['active' => $variant?->id === $v->id])>
                                            {{ $v->option_volume ?: $v->storefrontLabel() }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="modal-facts">
                            @foreach ([
                                'Аромат'   => $variant?->option_aroma ?: $product->aroma,
                                'Эффект'   => $product->effect,
                                'Доставка' => $freeShipping ? 'Бесплатно в заказе от ' . Money::rub($freeShipping) : null,
                            ] as $label => $value)
                                @if ($value)
                                    <p>
                                        <span>{{ $label }}</span>
                                        <b>{{ $value }}</b>
                                    </p>
                                @endif
                            @endforeach
                        </div>

                        <div class="modal-buy">
                            <strong>{{ Money::rub($variant?->price) }}</strong>
                            <button type="button" wire:click="addToCart"
                                    @disabled(! $product->isAvailable() || ! $variantId)>
                                {{ $product->isAvailable() ? 'Добавить в корзину' : 'Нет в наличии' }}
                                <b aria-hidden="true">→</b>
                            </button>
                        </div>

                        {{-- Состав, применение и отзывы живут на странице товара:
                             там они с документами и микроразметкой. Раскрывать их
                             копию внутри модалки — держать два места для одного
                             текста. Поэтому строка ведёт на полную карточку,
                             и стрелка указывает вбок, а не вниз: она не разворот. --}}
                        <a href="{{ route('catalog.show', $product) }}" class="modal-more">
                            Состав · применение · отзывы
                            <b aria-hidden="true">→</b>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
