<footer class="bg-ink text-paper mt-24">
    <div class="site-container py-16 grid gap-12 lg:grid-cols-[1.4fr_repeat(4,1fr)]">
        <div>
            <div class="eyebrow text-paper/50">Косметика для дома и профессионалов</div>
            <h2 class="mt-6 text-4xl md:text-5xl leading-[0.95]">
                Уход, который<br>подстраивается<br><span class="text-neon">под тебя.</span>
            </h2>
            <p class="mt-6 max-w-sm text-xs text-paper/60 leading-relaxed">
                Средства для понятного ежедневного ухода и точных профессиональных протоколов.
            </p>
            <a href="{{ route('catalog.index') }}" class="btn btn-neon mt-8">
                Перейти в каталог <span aria-hidden="true">→</span>
            </a>
        </div>

        <div>
            <div class="eyebrow text-paper/40">Магазин</div>
            <ul class="mt-5 space-y-3 text-xs">
                <li><a href="{{ route('catalog.index') }}" class="hover:text-neon">Все товары</a></li>
                <li><a href="{{ route('catalog.index', ['tab' => 'best']) }}" class="hover:text-neon">Бестселлеры</a></li>
                <li><a href="{{ route('catalog.index', ['tab' => 'bundles']) }}" class="hover:text-neon">Наборы</a></li>
                <li><a href="{{ route('catalog.index', ['tab' => 'pro']) }}" class="hover:text-neon">LIVSI PRO</a></li>
            </ul>
        </div>

        {{-- Колонка «Помощь» — как в прототипе. --}}
        <div>
            <div class="eyebrow text-paper/40">Помощь</div>
            <ul class="mt-5 space-y-3 text-xs">
                <li><a href="{{ route('legal.delivery') }}" class="hover:text-neon">Доставка и оплата</a></li>
                <li><a href="{{ route('legal.returns') }}" class="hover:text-neon">Возврат</a></li>
                <li><a href="{{ route('home') }}#faq" class="hover:text-neon">Вопросы и ответы</a></li>
                <li><a href="{{ route('contacts') }}" class="hover:text-neon">Контакты</a></li>
            </ul>
        </div>

        <div>
            <div class="eyebrow text-paper/40">О бренде</div>
            <ul class="mt-5 space-y-3 text-xs">
                <li><a href="{{ route('partners') }}" class="hover:text-neon">Стать партнёром</a></li>
                <li><a href="{{ route('contract') }}" class="hover:text-neon">Контрактное производство</a></li>
                <li><a href="{{ route('declarations') }}" class="hover:text-neon">Декларации соответствия</a></li>
            </ul>
        </div>

        <div>
            <div class="eyebrow text-paper/40">Связь</div>
            <ul class="mt-5 space-y-3 text-xs text-paper/70">
                <li>{{ $siteWorkHours }}</li>
                @if ($siteSeller?->email)
                    <li><a href="mailto:{{ $siteSeller->email }}" class="hover:text-neon">{{ $siteSeller->email }}</a></li>
                @endif
                <li><a href="{{ route('contacts') }}" class="hover:text-neon">Все контакты</a></li>
            </ul>
        </div>
    </div>

    {{-- Реквизиты продавца в подвале: их обязан указать продавец
         при дистанционной торговле, и покупатель должен видеть их
         на любой странице, а не искать в оферте. --}}
    @if ($siteSeller)
        <div class="site-container border-t border-white/10 py-6 text-[11px] leading-relaxed text-paper/50">
            {{ $siteSeller->legal_name }}@if ($siteSeller->inn), ИНН {{ $siteSeller->inn }}@endif
            @if ($siteSeller->ogrn), ОГРН{{ strlen((string) $siteSeller->ogrn) > 13 ? 'ИП' : '' }} {{ $siteSeller->ogrn }}@endif
            @if ($siteSeller->address)<br>{{ $siteSeller->address }}@endif
        </div>
    @endif

    <div class="site-container border-t border-white/10 py-6 flex flex-wrap gap-x-4 gap-y-2 justify-between text-[10px] uppercase tracking-[0.1em] text-paper/40">
        <span>© {{ date('Y') }} LIVSI · Made in Russia</span>

        <nav class="flex flex-wrap gap-x-4 gap-y-2" aria-label="Документы">
            @foreach ($siteLegalPages as $page)
                <a href="{{ route('legal.' . $page->slug) }}" class="hover:text-neon">{{ $page->menuTitle() }}</a>
            @endforeach
        </nav>
    </div>
</footer>