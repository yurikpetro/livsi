@if ($siteTopbar)
    <div class="bg-ink text-paper text-[10px] tracking-[0.14em] uppercase text-center py-2.5">
        {{ $siteTopbar }}
    </div>
@endif

<header class="sticky top-0 z-40 bg-paper/95 backdrop-blur border-b border-line">
    <div class="site-container h-16 grid grid-cols-[1fr_auto_1fr] items-center gap-4">
        <nav class="hidden md:flex items-center gap-7 text-[11px] font-bold uppercase tracking-[0.08em]">
            <a href="{{ route('catalog.index') }}" class="hover:text-green">Каталог</a>
            <a href="{{ route('partners') }}" class="hover:text-green">Стать партнёром</a>
            <a href="{{ route('contract') }}" class="hover:text-green">Контрактное производство</a>
        </nav>

        <a href="{{ route('home') }}" class="justify-self-start md:justify-self-center" aria-label="LIVSI — на главную">
            <img src="{{ asset('img/logo.svg') }}" alt="LIVSI" class="h-7 w-auto">
        </a>

        <div class="flex items-center justify-end gap-5">
            <a href="{{ route('catalog.index') }}" class="md:hidden text-[11px] font-bold uppercase tracking-[0.08em]">Каталог</a>

            @livewire('search-box')

            @livewire('cart-drawer')
        </div>
    </div>
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