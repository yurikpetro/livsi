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

            <a href="{{ route('cart.index') }}" class="relative flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] hover:text-green"
               aria-label="Корзина: {{ $cartCount ?? 0 }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path d="M4 7h16l-1.3 11.2a2 2 0 0 1-2 1.8H7.3a2 2 0 0 1-2-1.8L4 7Z"></path>
                    <path d="M9 7V5.5a3 3 0 0 1 6 0V7"></path>
                </svg>
                <span class="hidden sm:inline">Корзина</span>
                @if (($cartCount ?? 0) > 0)
                    <span class="grid h-4 min-w-4 place-items-center rounded-full bg-neon px-1 text-[10px] font-bold text-ink">{{ $cartCount }}</span>
                @endif
            </a>
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