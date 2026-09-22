@props(['product'])

@php
    use App\Services\FavoriteService;
    use App\Support\Money;

    $variant   = $product->defaultVariant();
    $image     = $product->primaryImage();
    $available = $product->isAvailable();
    $liked     = app(FavoriteService::class)->has($product);
@endphp

<article {{ $attributes->class(['product-card-lift group relative flex flex-col border border-line bg-paper']) }}>
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

        @if ($product->badge)
            <span class="pointer-events-none absolute left-3 top-3 z-[2] px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]
                @class([
                    'bg-neon' => $product->badge === 'new',
                    'bg-sand' => $product->badge === 'best',
                    'bg-pro-light' => $product->badge === 'pro',
                ])">
                {{ ['new' => 'NEW', 'best' => 'BEST', 'pro' => 'PRO'][$product->badge] ?? $product->badge }}
            </span>
        @endif

        @unless ($available)
            <span class="pointer-events-none absolute bottom-3 left-3 z-[2] bg-paper px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]">
                Нет в наличии
            </span>
        @endunless

        {{-- Сердечко поверх кнопки просмотра: иначе нажатие по нему
             открывало бы модалку. Без JavaScript это обычная форма. --}}
        <form method="POST" action="{{ route('favorites.toggle', $product) }}"
              class="absolute right-0 top-0 z-[4]" data-favorite>
            @csrf
            <button type="button" @class(['product-favorite', 'liked' => $liked])
                    aria-pressed="{{ $liked ? 'true' : 'false' }}"
                    aria-label="{{ $liked ? 'Убрать из избранного' : 'Добавить в избранное' }}"
                    onclick="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path d="M12 20.4S3.6 14.9 3.6 9.3a4.7 4.7 0 0 1 8.4-2.9 4.7 4.7 0 0 1 8.4 2.9c0 5.6-8.4 11.1-8.4 11.1Z"></path>
                </svg>
            </button>
        </form>
    </div>

    <div class="flex flex-1 flex-col p-4">
        <div class="flex items-start justify-between gap-3">
            <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                {{ $product->line?->title ?? ($product->is_pro ? 'PRO' : '') }}
            </span>

            @if ($product->rating)
                {{-- Источник рейтинга указывается всегда: отзывы собраны не на сайте.
                     См. docs/07-design-review.md §8.1. --}}
                <span class="text-[10px] text-muted whitespace-nowrap">
                    ★ {{ number_format($product->rating, 1, ',', '') }}
                    <span class="opacity-60">({{ $product->reviews_count }} на {{ $product->reviews_source }})</span>
                </span>
            @endif
        </div>

        <h3 class="mt-2 text-sm font-bold normal-case tracking-normal leading-snug">
            <a href="{{ route('catalog.show', $product) }}" class="hover:text-green">{{ $product->title }}</a>
        </h3>

        <p class="mt-1 text-xs text-muted leading-snug">{{ $product->short_description }}</p>

        @if ($variant)
            <p class="mt-3 text-[11px] text-muted">{{ $variant->storefrontLabel() }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-3 pt-5">
            <span class="text-base font-bold">{{ Money::rub($product->priceFrom()) }}</span>

            @if ($available && $variant)
                {{-- У товара с несколькими вариантами кладём в корзину вариант
                     по умолчанию: выбор объёма и аромата — в быстром просмотре
                     и на карточке товара. --}}
                {{-- Без JavaScript форма отправляется обычным POST и работает;
                     с Alpine перехватываем и открываем выдвижную корзину. --}}
                <form method="POST" action="{{ route('cart.add') }}" x-data
                      x-on:submit.prevent="Livewire.dispatch('cart-add', { variantId: Number(new FormData($el).get('variant_id')) })">
                    @csrf
                    <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                    <button type="submit" class="btn btn-outline !min-h-9 !px-3 !text-[10px]">
                        В корзину
                    </button>
                </form>
            @else
                <button type="button" disabled
                        class="btn btn-outline !min-h-9 !px-3 !text-[10px] opacity-40 cursor-not-allowed">
                    Нет в наличии
                </button>
            @endif
        </div>
    </div>
</article>
