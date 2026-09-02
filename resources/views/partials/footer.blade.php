<footer class="bg-ink text-paper mt-24">
    <div class="site-container py-16 grid gap-12 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
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
            </ul>
        </div>
    </div>

    <div class="site-container border-t border-white/10 py-6 flex flex-wrap gap-4 justify-between text-[10px] uppercase tracking-[0.1em] text-paper/40">
        <span>© {{ date('Y') }} LIVSI · Made in Russia</span>
        {{-- Юридические страницы получат собственные адреса на этапе контента:
             в прототипе они были якорями на главную (docs/07-design-review.md §4.1). --}}
        <span>Политика конфиденциальности · Публичная оферта · Согласие на обработку данных</span>
    </div>
</footer>