@extends('layouts.app')

@section('title', 'Каталог косметики — LIVSI')

@section('content')
    <section class="site-container pt-14">
        <h1 class="text-5xl md:text-6xl">Все товары.</h1>

        {{-- Три независимые оси. Каждая комбинация — собственный адрес страницы. --}}
        <div class="mt-10 space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="eyebrow w-24">Подборки</span>
                <a href="{{ route('catalog.index') }}"
                   class="chip @if (! $filters['tab'] && ! $filters['line'] && ! $filters['purpose'] && ! $filters['task']) chip-active @endif">Все</a>
                @foreach (['new' => 'Новинки', 'best' => 'Бестселлеры', 'bundles' => 'Наборы', 'pro' => 'PRO'] as $code => $title)
                    <a href="{{ route('catalog.index', array_filter(array_merge($filters, ['tab' => $code]))) }}"
                       class="chip @if ($filters['tab'] === $code) chip-active @endif">{{ $title }}</a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="eyebrow w-24">Линейка</span>
                @foreach ($lines as $line)
                    <a href="{{ route('catalog.index', array_filter(array_merge($filters, ['line' => $filters['line'] === $line->code ? null : $line->code]))) }}"
                       class="chip @if ($filters['line'] === $line->code) chip-active @endif">{{ $line->title }}</a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="eyebrow w-24">Назначение</span>
                @foreach ($purposes as $purpose)
                    <a href="{{ route('catalog.index', array_filter(array_merge($filters, ['purpose' => $filters['purpose'] === $purpose->code ? null : $purpose->code]))) }}"
                       class="chip @if ($filters['purpose'] === $purpose->code) chip-active @endif">{{ $purpose->title }}</a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="eyebrow w-24">Задача</span>
                @foreach ($tasks as $task)
                    <a href="{{ route('catalog.index', array_filter(array_merge($filters, ['task' => $filters['task'] === $task->code ? null : $task->code]))) }}"
                       class="chip @if ($filters['task'] === $task->code) chip-active @endif">{{ $task->title }}</a>
                @endforeach
            </div>
        </div>

        <div class="mt-8 flex items-center justify-between border-t border-line pt-4">
            <div class="flex gap-4 text-[11px] uppercase tracking-[0.08em] text-muted">
                @foreach (['popular' => 'По популярности', 'price_asc' => 'Сначала дешевле', 'price_desc' => 'Сначала дороже', 'rating' => 'По рейтингу'] as $code => $title)
                    <a href="{{ route('catalog.index', array_filter(array_merge($filters, ['sort' => $code]))) }}"
                       class="@if ($filters['sort'] === $code) text-ink font-bold @endif hover:text-ink">{{ $title }}</a>
                @endforeach
            </div>
            <span class="text-[11px] uppercase tracking-[0.08em] text-muted">{{ trans_choice(':count товар|:count товара|:count товаров', $products->count(), ['count' => $products->count()]) }}</span>
        </div>

        @if ($products->isEmpty())
            <div class="py-24 text-center">
                <p class="text-lg font-bold">Ничего не нашлось</p>
                <p class="mt-2 text-xs text-muted">Попробуйте снять часть фильтров.</p>
                <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-6">Сбросить фильтры</a>
            </div>
        @else
            <div class="mt-8 grid gap-4 grid-cols-2 lg:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        @endif
    </section>
@endsection