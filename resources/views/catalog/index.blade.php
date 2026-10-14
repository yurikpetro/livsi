@extends('layouts.app')

@section('title', $query->q !== ''
    ? 'Поиск: ' . $query->q . ' — LIVSI'
    : 'Каталог косметики — LIVSI')

@section('content')
    @php
        $categoryTitle = match (true) {
            $query->tab === 'pro' && $query->purposes === ['body', 'face'] => 'PRO SKIN',
            $query->tab === 'pro' && $query->purposes === ['manicure', 'pedicure'] => 'PRO MANICURE / PEDICURE',
            $query->line !== null => $facets['line']->firstWhere('code', $query->line)?->title ?? 'Все товары',
            isset(\App\Services\CatalogQuery::TABS[$query->tab]) => \App\Services\CatalogQuery::TABS[$query->tab],
            default => 'Все товары',
        };
    @endphp
    <section class="site-container pt-10 md:pt-14">
        @if ($query->q !== '')
            <div class="eyebrow">Поиск</div>
            <h1 class="mt-3 text-3xl md:text-5xl normal-case tracking-tight">«{{ $query->q }}»</h1>
        @else
            <h1 class="text-4xl md:text-6xl">{{ $categoryTitle }}.</h1>
        @endif

        {{-- Подборки и линейки по макету --}}
        <div class="catalog-toolbar mt-8 flex flex-wrap items-center gap-2">
            <a href="{{ $query->urlForCategory() }}" class="chip @if (! $query->line && ! $query->purposes && ! $query->tasks && $query->tab === '') chip-active @endif">Все товары</a>
            @foreach (['new', 'best', 'bundles'] as $code)
                <a href="{{ $query->urlForCategory(['tab' => $code]) }}"
                   class="chip @if ($query->isActive('tab', $code)) chip-active @endif">{{ \App\Services\CatalogQuery::TABS[$code] }}</a>
            @endforeach
            @foreach ($facets['line'] as $value)
                <a href="{{ $query->urlForCategory(['line' => $value->code]) }}" class="chip @if ($query->isActive('line', $value->code)) chip-active @endif">{{ $value->title }}</a>
            @endforeach
            <a href="{{ $query->urlForCategory(['tab' => 'pro', 'purpose' => ['body', 'face']]) }}" class="chip @if ($query->tab === 'pro' && $query->purposes === ['body', 'face']) chip-active @endif">PRO SKIN</a>
            <a href="{{ $query->urlForCategory(['tab' => 'pro', 'purpose' => ['manicure', 'pedicure']]) }}" class="chip @if ($query->tab === 'pro' && $query->purposes === ['manicure', 'pedicure']) chip-active @endif">PRO MANICURE / PEDICURE</a>
        </div>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-line pt-4">
            <label class="flex items-center gap-3 text-xs"><span class="text-muted">Сортировка</span>
                <select class="catalog-sort" data-catalog-sort aria-label="Сортировка товаров">
                    @foreach (\App\Services\CatalogQuery::SORTS as $code => $title)
                        <option value="{{ $query->urlWithSort($code) }}" @selected($query->sort === $code)>{{ $title }}</option>
                    @endforeach
                </select>
            </label>
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
            <div class="reveal-stagger mt-8 grid gap-3 grid-cols-2 md:gap-4 lg:grid-cols-4 xl:grid-cols-6" data-reveal>
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
