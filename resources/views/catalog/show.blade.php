@extends('layouts.app')

@section('title', $product->seo_title ?: $product->title . ' — LIVSI')
@section('description', $product->seo_description ?: $product->short_description)

@section('content')
    @php
        use App\Support\Money;
        $variant = $product->defaultVariant();
        $image   = $product->primaryImage();
    @endphp

    <section class="site-container pt-10">
        <nav class="text-[10px] uppercase tracking-[0.1em] text-muted">
            <a href="{{ route('catalog.index') }}" class="hover:text-ink">Каталог</a>
            @if ($product->line)
                <span class="mx-2">/</span>
                <a href="{{ route('catalog.index', ['line' => $product->line->code]) }}" class="hover:text-ink">{{ $product->line->title }}</a>
            @endif
            <span class="mx-2">/</span>
            <span class="text-ink">{{ $product->title }}</span>
        </nav>

        <div class="mt-8 grid gap-12 lg:grid-cols-2">
            <div class="bg-shell">
                @if ($image)
                    <img src="{{ asset($image->path) }}" alt="{{ $image->alt }}"
                         width="900" height="1100" class="aspect-[4/5] w-full object-cover">
                @endif
                {{-- Галерея на 4–8 кадров появится, когда придут чистые фото
                     без маркетплейсной инфографики (вопрос 14.6). --}}
            </div>

            <div class="max-w-lg">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                        {{ $product->line?->title }}@if ($product->is_pro) PRO @endif
                    </span>
                    @if ($product->rating)
                        <span class="text-[11px] text-muted">
                            ★ {{ number_format($product->rating, 1, ',', '') }} ·
                            {{ $product->reviews_count }} отзывов на {{ $product->reviews_source }}
                        </span>
                    @endif
                </div>

                <h1 class="mt-5 text-4xl md:text-5xl normal-case tracking-tight">{{ $product->title }}</h1>
                <p class="mt-4 text-sm text-muted leading-relaxed">{{ $product->short_description }}</p>

                {{-- Выбор варианта и добавление в корзину — одна форма, работает без JS.
                     Варианты идут по двум осям: объём × аромат. --}}
                <form method="POST" action="{{ route('cart.add') }}" class="mt-8">
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
                                    {{ $v->optionLabel() }}
                                </label>
                            @endforeach
                        </div>
                    @elseif ($variant)
                        <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                    @endif

                    <dl class="mt-8 divide-y divide-line border-y border-line text-xs">
                        @if ($product->aroma)
                            <div class="flex justify-between gap-6 py-3">
                                <dt class="text-muted uppercase tracking-[0.08em] text-[10px]">Аромат</dt>
                                <dd class="font-bold text-right">{{ $product->aroma }}</dd>
                            </div>
                        @endif
                        @if ($product->effect)
                            <div class="flex justify-between gap-6 py-3">
                                <dt class="text-muted uppercase tracking-[0.08em] text-[10px]">Эффект</dt>
                                <dd class="font-bold text-right">{{ $product->effect }}</dd>
                            </div>
                        @endif
                        @if ($product->purposes->isNotEmpty())
                            <div class="flex justify-between gap-6 py-3">
                                <dt class="text-muted uppercase tracking-[0.08em] text-[10px]">Назначение</dt>
                                <dd class="font-bold text-right">{{ $product->purposes->pluck('title')->implode(', ') }}</dd>
                            </div>
                        @endif
                        @if ($product->tasks->isNotEmpty())
                            <div class="flex justify-between gap-6 py-3">
                                <dt class="text-muted uppercase tracking-[0.08em] text-[10px]">Задача</dt>
                                <dd class="font-bold text-right">{{ $product->tasks->pluck('title')->implode(', ') }}</dd>
                            </div>
                        @endif
                        @if ($variant?->sku)
                            <div class="flex justify-between gap-6 py-3">
                                <dt class="text-muted uppercase tracking-[0.08em] text-[10px]">Артикул</dt>
                                <dd class="text-right">{{ $variant->sku }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="mt-8 flex items-center gap-6">
                        <span class="text-3xl font-black">{{ Money::rub($variant?->price) }}</span>
                        <button type="submit"
                                @disabled(! $product->isAvailable())
                                class="btn btn-neon flex-1 disabled:opacity-40 disabled:cursor-not-allowed">
                            {{ $product->isAvailable() ? 'Добавить в корзину' : 'Нет в наличии' }}
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>
                </form>

                @if ($product->active_ingredients)
                    <details class="mt-8 border-t border-line py-4">
                        <summary class="cursor-pointer text-xs font-bold uppercase tracking-[0.08em]">Действующие вещества</summary>
                        <p class="mt-3 text-xs text-muted leading-relaxed">{{ $product->active_ingredients }}</p>
                    </details>
                @endif
            </div>
        </div>
    </section>

    @if ($related->isNotEmpty())
        <section class="site-container mt-24">
            <h2 class="text-3xl md:text-4xl">С этим покупают</h2>
            <div class="mt-8 grid gap-4 grid-cols-2 lg:grid-cols-4">
                @foreach ($related as $item)
                    <x-product-card :product="$item" />
                @endforeach
            </div>
        </section>
    @endif
@endsection
