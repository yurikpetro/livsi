@if ($siteTopbar)
    <div class="bg-ink px-4 py-2.5 text-center text-[9px] uppercase leading-tight tracking-[0.12em] text-paper sm:text-[10px] sm:tracking-[0.14em]">
        {{ $siteTopbar }}
    </div>
@endif

<header x-data="{ menu: false }" class="sticky top-0 z-40 border-b border-line bg-paper/95 backdrop-blur">
    <div class="site-container grid h-14 grid-cols-[1fr_auto_1fr] items-center gap-3 md:h-16 md:gap-4">
        {{-- На мобильном навигация уезжает в выдвижное меню: три пункта
             с длинными названиями в строку не помещаются. --}}
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
                <a href="{{ route('catalog.index') }}" class="hover:text-green">Каталог</a>
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

    {{-- Мобильное меню: линейки тоже сюда, иначе с телефона до них
         не добраться иначе как через фильтры каталога. --}}
    <nav id="mobile-nav" x-show="menu" x-cloak x-collapse
         class="border-t border-line bg-paper md:hidden">
        <div class="site-container py-4">
            <a href="{{ route('catalog.index') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Каталог</a>
            <a href="{{ route('catalog.pro') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">LIVSI PRO</a>
            <a href="{{ route('partners') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Стать партнёром</a>
            <a href="{{ route('contract') }}" class="block py-2.5 text-sm font-bold uppercase tracking-[0.06em]">Контрактное производство</a>

            <div class="mt-3 border-t border-line pt-3">
                <div class="eyebrow">Линейки</div>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($siteLines as $line)
                        <a href="{{ route('catalog.line', $line) }}" class="chip">{{ $line->title }}</a>
                    @endforeach
                </div>
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
