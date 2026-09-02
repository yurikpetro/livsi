@extends('layouts.app')

@php
    $title    = $isPro ? 'PRO' : $line->title;
    $subtitle = $isPro ? 'профессиональная' : $line->subtitle;
    $bg       = $isPro ? 'var(--color-pro-light)' : ($line->color_bg ?: 'var(--color-shell)');
    $ink      = $isPro ? 'var(--color-pro-blue)' : ($line->color_ink ?: 'var(--color-ink)');
    $text     = $isPro
        ? 'Отдельная линейка профессиональных товаров для B2B-направления: точные протоколы, развёрнутые описания и указание действующих веществ.'
        : $line->description;
@endphp

@section('title', ($isPro ? 'LIVSI PRO' : ($line->seo_title ?: 'Линейка ' . $line->title)) . ' — LIVSI')
@section('description', $isPro ? $text : ($line->seo_description ?: $text))

@section('content')
    {{-- Цвет линейки берётся из брендбука и хранится в самой линейке,
         поэтому новую линейку можно завести из админки без правок вёрстки. --}}
    <section class="py-14 md:py-20" style="background: {{ $bg }}">
        <div class="site-container">
            <div class="eyebrow" style="color: {{ $ink }}">
                {{ $isPro ? 'Для мастеров' : 'Ароматическая линейка' }}
            </div>

            <h1 class="mt-4 text-5xl md:text-7xl">
                {{ $title }}
                @if ($subtitle)
                    <span class="block text-2xl font-bold normal-case tracking-normal md:text-3xl" style="color: {{ $ink }}">
                        {{ $subtitle }}
                    </span>
                @endif
            </h1>

            @if ($text)
                <p class="mt-6 max-w-2xl text-sm leading-relaxed md:text-base">{{ $text }}</p>
            @endif
        </div>
    </section>

    <section class="site-container mt-10 md:mt-14">
        <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-line pb-4">
            <h2 class="text-xl md:text-2xl">Товары линейки</h2>
            <span class="text-[11px] uppercase tracking-[0.08em] text-muted">
                {{ trans_choice(':count товар|:count товара|:count товаров', $products->count(), ['count' => $products->count()]) }}
            </span>
        </div>

        @if ($products->isEmpty())
            <div class="py-20 text-center">
                <p class="text-lg font-bold">Пока пусто</p>
                <p class="mt-2 text-xs text-muted">Товары этой линейки скоро появятся.</p>
                <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-6">Весь каталог <span aria-hidden="true">→</span></a>
            </div>
        @else
            <div class="mt-6 grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            <div class="mt-10 flex justify-end">
                <a href="{{ $isPro ? route('catalog.index', ['tab' => 'pro']) : route('catalog.index', ['line' => $line->code]) }}"
                   class="btn btn-outline">
                    Открыть в каталоге с фильтрами <span aria-hidden="true">→</span>
                </a>
            </div>
        @endif
    </section>
@endsection
