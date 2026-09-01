@props(['product'])

@php
    use App\Support\Money;

    $variant   = $product->defaultVariant();
    $image     = $product->primaryImage();
    $available = $product->isAvailable();
@endphp

<article class="group flex flex-col border border-line bg-paper">
    <a href="{{ route('catalog.show', $product) }}" class="relative block aspect-[4/5] overflow-hidden bg-shell">
        @if ($image)
            <img src="{{ asset($image->path) }}" alt="{{ $image->alt }}"
                 loading="lazy" width="600" height="750"
                 class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
        @endif

        @if ($product->badge)
            <span class="absolute left-3 top-3 px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]
                @class([
                    'bg-neon' => $product->badge === 'new',
                    'bg-sand' => $product->badge === 'best',
                    'bg-pro-light' => $product->badge === 'pro',
                ])">
                {{ ['new' => 'NEW', 'best' => 'BEST', 'pro' => 'PRO'][$product->badge] ?? $product->badge }}
            </span>
        @endif

        @unless ($available)
            <span class="absolute right-3 top-3 bg-paper px-2 py-1 text-[9px] font-bold uppercase tracking-[0.1em]">
                Нет в наличии
            </span>
        @endunless
    </a>

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
            <p class="mt-3 text-[11px] text-muted">{{ $variant->optionLabel() }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-3 pt-5">
            <span class="text-base font-bold">{{ Money::rub($product->priceFrom()) }}</span>

            @if ($available && $variant)
                {{-- У товара с несколькими вариантами кладём в корзину вариант
                     по умолчанию: выбор объёма и аромата — на карточке товара. --}}
                <form method="POST" action="{{ route('cart.add') }}">
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
