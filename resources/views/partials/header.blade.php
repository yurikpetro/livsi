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

        <div class="flex items-center justify-end gap-4">
            {{-- Поиск, избранное, аккаунт и корзина подключаются в следующих инкрементах. --}}
            <button type="button" class="text-ink/70 hover:text-ink" aria-label="Поиск">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                </svg>
            </button>
            <a href="{{ route('catalog.index') }}" class="md:hidden text-[11px] font-bold uppercase tracking-[0.08em]">Каталог</a>
        </div>
    </div>
</header>