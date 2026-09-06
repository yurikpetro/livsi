@extends('layouts.app')

@section('title', $query->q !== ''
    ? 'Поиск: ' . $query->q . ' — LIVSI'
    : 'Каталог косметики — LIVSI')

@section('content')
    <section class="site-container pt-10 md:pt-14">
        @if ($query->q !== '')
            <div class="eyebrow">Поиск</div>
            <h1 class="mt-3 text-3xl md:text-5xl normal-case tracking-tight">«{{ $query->q }}»</h1>
        @else
            <h1 class="text-4xl md:text-6xl">Все товары.</h1>
        @endif

        {{-- Быстрые подборки поверх осей --}}
        <div class="mt-8 flex flex-wrap items-center gap-2">
            <span class="eyebrow w-full sm:w-24">Подборки</span>
            @foreach (\App\Services\CatalogQuery::TABS as $code => $title)
                <a href="{{ $query->urlToggle('tab', $code) }}"
                   class="chip @if ($query->isActive('tab', $code)) chip-active @endif">{{ $title }}</a>
            @endforeach
        </div>

        {{-- Три независимые оси. Внутри оси — ИЛИ, между осями — И.
             Счётчик считается без учёта своей оси, иначе фильтр становится тупиком. --}}
        <div class="mt-4 space-y-3 border-t border-line pt-5">
            @foreach ([
                'line'    => ['Линейка', $facets['line']],
                'purpose' => ['Назначение', $facets['purpose']],
                'task'    => ['Задача', $facets['task']],
            ] as $axis => [$label, $values])
                <div class="flex flex-wrap items-center gap-2">
                    <span class="eyebrow w-full sm:w-24">{{ $label }}</span>
                    @foreach ($values as $value)
                        @php $empty = $value->products_count === 0 && ! $query->isActive($axis, $value->code); @endphp
                        <a href="{{ $empty ? '#' : $query->urlToggle($axis, $value->code) }}"
                           @class([
                               'chip gap-1.5',
                               'chip-active' => $query->isActive($axis, $value->code),
                               'pointer-events-none opacity-35' => $empty,
                           ])
                           @if ($empty) aria-disabled="true" tabindex="-1" @endif>
                            {{ $value->title }}
                            <span class="text-[9px] opacity-60">{{ $value->products_count }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Активные фильтры: каждый снимается по отдельности --}}
        @if ($chips->isNotEmpty())
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <span class="eyebrow w-full sm:w-24">Выбрано</span>
                @foreach ($chips as $chip)
                    <a href="{{ $chip['url'] }}" class="chip chip-active gap-2">
                        {{ $chip['label'] }}
                        <span aria-hidden="true" class="text-[11px] leading-none">✕</span>
                    </a>
                @endforeach
                <a href="{{ route('catalog.index') }}"
                   class="text-[10px] uppercase tracking-[0.08em] text-muted underline hover:text-ink">
                    Сбросить всё
                </a>
            </div>
        @endif

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-line pt-4">
            <div class="flex flex-wrap gap-4 text-[11px] uppercase tracking-[0.08em] text-muted">
                @foreach (\App\Services\CatalogQuery::SORTS as $code => $title)
                    <a href="{{ $query->urlWithSort($code) }}"
                       class="@if ($query->sort === $code) font-bold text-ink @endif hover:text-ink">{{ $title }}</a>
                @endforeach
            </div>
            <span class="text-[11px] uppercase tracking-[0.08em] text-muted">
                {{ \App\Support\Plural::products($products->total()) }}
            </span>
        </div>

        @if ($products->isEmpty())
            <div class="py-24 text-center">
                <p class="text-lg font-bold">Ничего не нашлось</p>
                <p class="mt-2 text-xs text-muted">
                    @if ($query->q !== '')
                        По запросу «{{ $query->q }}» ничего нет. Попробуйте короче или другими словами.
                    @else
                        Попробуйте снять часть фильтров.
                    @endif
                </p>
                @if ($query->hasFilters())
                    <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-6">Сбросить фильтры</a>
                @endif
            </div>
        @else
            <div class="reveal-stagger mt-8 grid gap-3 grid-cols-2 md:gap-4 lg:grid-cols-4" data-reveal>
                @foreach ($products as $i => $product)
                    <x-product-card :product="$product" style="--index: {{ $i }}" />
                @endforeach
            </div>

            @if ($products->hasPages())
                <div class="mt-12">
                    {{ $products->links() }}
                </div>
            @endif
        @endif
    </section>
@endsection
