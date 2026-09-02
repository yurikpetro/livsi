@php
    /**
     * Меню каталога с вложенностью — как в макете: «Уход» раскрывает
     * ароматические линейки, PRO раскрывает свои подкатегории.
     *
     * Подкатегории PRO собраны из существующих осей навигации, а не из новой
     * сущности: в прототипе SKIN и MANICURE / PEDICURE на текущих данных
     * возвращают один и тот же единственный PRO-товар, различить их
     * невозможно. Толкование требует подтверждения — docs/07-design-review.md §13.
     */
    $catalogMenu = [
        ['label' => 'Все товары', 'url' => route('catalog.index')],
        ['label' => 'Новинки',    'url' => route('catalog.index', ['tab' => 'new'])],
        ['label' => 'Наборы',     'url' => route('catalog.index', ['tab' => 'bundles'])],
        [
            'label'    => 'Уход',
            'url'      => route('catalog.index', ['tab' => 'care']),
            'key'      => 'care',
            'children' => $siteLines->map(fn ($line) => [
                'label' => $line->title,
                'url'   => route('catalog.line', $line),
                'color' => $line->color_bg,
            ])->all(),
        ],
        [
            'label'    => 'PRO',
            'url'      => route('catalog.pro'),
            'key'      => 'pro',
            'children' => [
                [
                    'label' => 'SKIN',
                    'url'   => route('catalog.index', ['tab' => 'pro', 'purpose' => ['body', 'face']]),
                    'color' => '#99d0f7',
                ],
                [
                    'label' => 'MANICURE / PEDICURE',
                    'url'   => route('catalog.index', ['tab' => 'pro', 'purpose' => ['manicure', 'pedicure']]),
                    'color' => '#99d0f7',
                ],
            ],
        ],
    ];
@endphp

@if ($siteTopbar)
    <div class="bg-ink px-4 py-2.5 text-center text-[9px] uppercase leading-tight tracking-[0.12em] text-paper sm:text-[10px] sm:tracking-[0.14em]">
        {{ $siteTopbar }}
    </div>
@endif

<header x-data="{ menu: false, catalog: false, sub: null }"
        x-on:keydown.escape.window="menu = false; catalog = false; sub = null"
        class="sticky top-0 z-40 border-b border-line bg-paper/95 backdrop-blur">
    <div class="site-container grid h-14 grid-cols-[1fr_auto_1fr] items-center gap-3 md:h-16 md:gap-4">
        <div class="flex items-center">
            <button type="button" x-on:click="menu = !menu"
                    class="-ml-1 p-1 md:hidden"
                    :aria-expanded="menu ? 'true' : 'false'"
                    aria-controls="mobile-nav"
                    aria-label="Меню">
                {{-- Два отдельных svg, а не x-if внутри одного: шаблонный тег
                     в SVG-пространстве имён не разворачивается. --}}
                <svg x-show="! menu" width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"></path>
                </svg>
                <svg x-show="menu" x-cloak width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="m6 6 12 12M18 6 6 18"></path>
                </svg>
            </button>

            <nav class="hidden items-center gap-7 text-[11px] font-bold uppercase tracking-[0.08em] md:flex">
                {{-- «Каталог» — раскрывающееся меню: наведением на десктопе
                     и по клику с клавиатуры. Сам пункт остаётся ссылкой,
                     чтобы работал и без JavaScript, и в поиске. --}}
                <div class="relative"
                     x-on:mouseenter="catalog = true"
                     x-on:mouseleave="catalog = false; sub = null">
                    <a href="{{ route('catalog.index') }}"
                       x-on:click.prevent="catalog = ! catalog"
                       x-on:focus="catalog = true"
                       :aria-expanded="catalog ? 'true' : 'false'"
                       aria-controls="catalog-menu"
                       class="flex items-center gap-1.5 py-2 hover:text-green">
                        Каталог
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" aria-hidden="true"
                             :class="catalog && 'rotate-180'" class="transition">
                            <path d="m6 9 6 6 6-6"></path>
                        </svg>
                    </a>

                    <div id="catalog-menu"
                         x-show="catalog"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="-translate-y-1 opacity-0"
                         x-transition:enter-end="translate-y-0 opacity-100"
                         class="absolute left-0 top-full w-72 border border-line bg-paper py-3 shadow-xl">
                        @foreach ($catalogMenu as $item)
                            @if (empty($item['children']))
                                <a href="{{ $item['url'] }}"
                                   class="block px-5 py-2.5 text-sm font-bold normal-case tracking-normal hover:bg-shell">
                                    {{ $item['label'] }}
                                </a>
                            @else
                                {{-- Раздел с вложенностью: по наведению подсвечивается
                                     сам раздел и раскрывается список под ним. --}}
                                <div x-on:mouseenter="sub = '{{ $item['key'] }}'">
                                    <a href="{{ $item['url'] }}"
                                       :class="sub === '{{ $item['key'] }}' && 'bg-shell'"
                                       :aria-expanded="sub === '{{ $item['key'] }}' ? 'true' : 'false'"
                                       aria-controls="catalog-sub-{{ $item['key'] }}"
                                       class="block px-5 py-2.5 text-sm font-bold normal-case tracking-normal">
                                        {{ $item['label'] }}
                                    </a>

                                    <div id="catalog-sub-{{ $item['key'] }}"
                                         x-show="sub === '{{ $item['key'] }}'"
                                         x-cloak x-collapse
                                         class="pb-1">
                                        @foreach ($item['children'] as $child)
                                            <a href="{{ $child['url'] }}"
                                               class="flex items-center gap-2.5 px-5 py-2 pl-8 text-[11px] font-bold tracking-[0.06em] hover:bg-shell">
                                                <span class="h-2 w-2 shrink-0" style="background: {{ $child['color'] }}"></span>
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        <div class="mx-5 my-2 border-t border-line"></div>

                        <a href="{{ route('declarations') }}"
                           class="flex items-center justify-between gap-4 px-5 py-2.5 hover:bg-shell">
                            <span>Декларации</span>
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </div>

                <a href="{{ route('partners') }}" class="hover:text-green">Стать партнёром</a>
                <a href="{{ route('contract') }}" class="hover:text-green">Контрактное производство</a>
            </nav>
        </div>

        <a href="{{ route('home') }}" class="justify-self-center" aria-label="LIVSI — на главную">
            <img src="{{ asset('img/logo.svg') }}" alt="LIVSI" class="h-6 w-auto md:h-7">
        </a>

        <div class="flex items-center justify-end gap-4 md:gap-5">
            @livewire('search-box')
            @livewire('cart-drawer')
        </div>
    </div>

    {{-- Мобильное меню: та же структура, но вложенность раскрыта сразу —
         на телефоне лишний уровень наведения только мешает. --}}
    <nav id="mobile-nav" x-show="menu" x-cloak x-collapse
         class="border-t border-line bg-paper md:hidden">
        <div class="site-container py-4">
            @foreach ($catalogMenu as $item)
                <a href="{{ $item['url'] }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">{{ $item['label'] }}</a>

                @if (! empty($item['children']))
                    <div class="mb-1 flex flex-wrap gap-2 pl-1">
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['url'] }}" class="chip gap-2">
                                <span class="h-2 w-2 shrink-0" style="background: {{ $child['color'] }}"></span>
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach

            <a href="{{ route('declarations') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Декларации</a>

            <div class="mt-3 border-t border-line pt-3">
                <a href="{{ route('partners') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Стать партнёром</a>
                <a href="{{ route('contract') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Контрактное производство</a>
            </div>
        </div>
    </nav>
</header>

@if (session('cart_status') || session('cart_error'))
    <div @class([
        'site-container mt-4 border px-4 py-3 text-xs',
        'border-green text-green' => session('cart_status'),
        'border-danger text-danger' => session('cart_error'),
    ])>
        {{ session('cart_status') ?: session('cart_error') }}
    </div>
@endif
