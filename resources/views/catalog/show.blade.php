@extends('layouts.app')

@php
    use App\Support\Money;

    $variant = $product->defaultVariant();
    $images  = $product->images->isNotEmpty() ? $product->images : collect();
    $cover   = $product->primaryImage();

    // Микроразметка товара. AggregateRating намеренно не выводим: отзывы
    // собраны на Ozon, а не на сайте, и разметка рейтинга, не подтверждённого
    // содержимым страницы, наказывается поисковиками.
    // См. docs/07-design-review.md §8.1.
    $jsonLd = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product->title,
        'description' => $product->short_description,
        'sku'         => $variant?->sku,
        'gtin13'      => $variant?->barcode,
        'brand'       => ['@type' => 'Brand', 'name' => 'LIVSI'],
        'image'       => $images->map(fn ($i) => asset($i->path))->values()->all(),
        'category'    => $product->line?->title,
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => route('catalog.show', $product),
            'priceCurrency' => 'RUB',
            'price'         => $variant ? number_format($variant->price / 100, 2, '.', '') : null,
            'availability'  => $product->isAvailable()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
        ],
    ];
@endphp

@section('title', $product->seo_title ?: $product->title . ' — LIVSI')
@section('description', $product->seo_description ?: $product->short_description)
@section('og_type', 'product')
@section('og_image', $cover ? asset($cover->path) : asset('img/catalog/fresh-hero.jpg'))

@push('head')
    <script type="application/ld+json">
        @json($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    </script>
@endpush

@section('content')
    <section class="site-container pt-6 md:pt-10">
        <nav class="text-[10px] uppercase tracking-[0.1em] text-muted">
            <a href="{{ route('catalog.index') }}" class="hover:text-ink">Каталог</a>
            @if ($product->line)
                <span class="mx-2">/</span>
                <a href="{{ route('catalog.line', $product->line) }}" class="hover:text-ink">{{ $product->line->title }}</a>
            @endif
            <span class="mx-2">/</span>
            <span class="text-ink">{{ $product->title }}</span>
        </nav>

        <div class="mt-6 grid gap-8 lg:mt-8 lg:grid-cols-2 lg:gap-12">
            {{-- Галерея. Переключение на Alpine: серверный запрос тут не нужен. --}}
            <div x-data="{ active: 0 }" class="flex flex-col gap-3">
                <div class="bg-shell">
                    @foreach ($images as $i => $image)
                        <img x-show="active === {{ $i }}"
                             src="{{ asset($image->path) }}" alt="{{ $image->alt }}"
                             width="900" height="1100"
                             @if ($i > 0) loading="lazy" @endif
                             class="aspect-[4/5] w-full object-cover">
                    @endforeach

                    @if ($images->isEmpty())
                        <div class="aspect-[4/5] w-full"></div>
                    @endif
                </div>

                @if ($images->count() > 1)
                    <div class="flex gap-2 overflow-x-auto">
                        @foreach ($images as $i => $image)
                            <button type="button" x-on:click="active = {{ $i }}"
                                    :class="active === {{ $i }} ? 'border-ink' : 'border-line'"
                                    class="w-16 shrink-0 border bg-shell transition"
                                    aria-label="Кадр {{ $i + 1 }}">
                                <img src="{{ asset($image->path) }}" alt="" loading="lazy"
                                     width="120" height="150" class="aspect-[4/5] w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="max-w-lg">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                        @if ($product->line)
                            <a href="{{ route('catalog.line', $product->line) }}" class="hover:text-ink">{{ $product->line->title }}</a>
                        @endif
                        @if ($product->is_pro)
                            <a href="{{ route('catalog.pro') }}" class="ml-1 bg-pro-light px-1.5 py-0.5 text-ink">PRO</a>
                        @endif
                    </span>
                    @if ($product->rating)
                        {{-- Источник рейтинга обязателен: отзывы собраны не на сайте. --}}
                        <span class="text-[11px] text-muted">
                            ★ {{ number_format($product->rating, 1, ',', '') }} ·
                            {{ $product->reviews_count }} отзывов на {{ $product->reviews_source }}
                        </span>
                    @endif
                </div>

                <h1 class="mt-4 text-3xl normal-case tracking-tight md:mt-5 md:text-5xl">{{ $product->title }}</h1>
                <p class="mt-3 text-sm leading-relaxed text-muted md:mt-4">{{ $product->short_description }}</p>

                {{-- Выбор варианта и добавление в корзину — одна форма, работает без JS.
                     Варианты идут по двум осям: объём × аромат. --}}
                <form method="POST" action="{{ route('cart.add') }}" class="mt-6 md:mt-8" x-data
                      x-on:submit.prevent="Livewire.dispatch('cart-add', { variantId: Number(new FormData($el).get('variant_id')) })">
                    @csrf

                    @if ($product->variants->count() > 1)
                        <div class="eyebrow">Вариант</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($product->variants as $v)
                                @php $out = $v->available() < 1; @endphp
                                <label @class([
                                    'chip cursor-pointer has-[:checked]:bg-neon',
                                    'opacity-40 line-through cursor-not-allowed' => $out,
                                ])>
                                    <input type="radio" name="variant_id" value="{{ $v->id }}"
                                           class="sr-only" @checked($v->is_default && ! $out) @disabled($out)>
                                    {{ $v->storefrontLabel() }}
                                </label>
                            @endforeach
                        </div>
                    @elseif ($variant)
                        <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                    @endif

                    <dl class="mt-6 divide-y divide-line border-y border-line text-xs md:mt-8">
                        @foreach ([
                            'Аромат'     => $product->aroma,
                            'Эффект'     => $product->effect,
                            'Назначение' => $product->purposes->pluck('title')->implode(', ') ?: null,
                            'Задача'     => $product->tasks->pluck('title')->implode(', ') ?: null,
                            'Срок годности' => $product->shelf_life,
                            'Артикул'    => $variant?->sku,
                        ] as $label => $value)
                            @if ($value)
                                <div class="flex justify-between gap-6 py-3">
                                    <dt class="text-[10px] uppercase tracking-[0.08em] text-muted">{{ $label }}</dt>
                                    <dd class="text-right font-bold">{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    <div class="mt-6 flex flex-wrap items-center gap-4 md:mt-8 md:gap-6">
                        <span class="text-3xl font-black">{{ Money::rub($variant?->price) }}</span>
                        <button type="submit"
                                @disabled(! $product->isAvailable())
                                class="btn btn-neon flex-1 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ $product->isAvailable() ? 'Добавить в корзину' : 'Нет в наличии' }}
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </form>

                {{-- Состав, применение и документы — аккордеоны: на витрине они
                     нужны не всем, но мастера читают состав внимательно. --}}
                <div class="mt-8 divide-y divide-line border-t border-line">
                    @foreach ([
                        'Состав' => $product->composition,
                        'Способ применения' => $product->application,
                        'Действующие вещества' => $product->active_ingredients,
                    ] as $label => $text)
                        @if ($text)
                            <details class="group py-4">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                                    <span class="text-xs font-bold uppercase tracking-[0.08em]">{{ $label }}</span>
                                    <span class="text-lg text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
                                </summary>
                                <p class="mt-3 text-xs leading-relaxed text-muted">{{ $text }}</p>
                            </details>
                        @endif
                    @endforeach

                    @if ($product->documents)
                        <details class="group py-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                                <span class="text-xs font-bold uppercase tracking-[0.08em]">Документы</span>
                                <span class="text-lg text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
                            </summary>
                            <ul class="mt-3 space-y-2 text-xs">
                                @foreach ($product->documents as $doc)
                                    <li>
                                        <a href="{{ $doc['url'] ?? '#' }}" class="underline hover:text-green"
                                           target="_blank" rel="noopener">{{ $doc['title'] ?? 'Документ' }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if ($related->isNotEmpty())
        <section class="site-container mt-16 md:mt-24">
            <h2 class="text-2xl md:text-4xl">С этим покупают</h2>
            <div class="mt-6 grid grid-cols-2 gap-3 md:mt-8 md:gap-4 lg:grid-cols-4">
                @foreach ($related as $item)
                    <x-product-card :product="$item" />
                @endforeach
            </div>
        </section>
    @endif
@endsection
