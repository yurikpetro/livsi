@extends('layouts.app')

@section('content')
    @php $hero = $lines->firstWhere('code', 'fresh'); @endphp

    {{-- Hero. Фотография следует за курсором и медленно уезжает при
         прокрутке — смещение приходит в переменных из resources/js/app.js. --}}
    <section class="relative">
        <div class="hero relative min-h-[520px] md:min-h-[640px] overflow-hidden bg-shell" data-hero>
            <div class="hero-photo">
                <x-img path="img/catalog/fresh-hero.jpg" alt="" sizes="100vw"
                       loading="eager" fetchpriority="high" />
            </div>
            <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/30 to-transparent"></div>

            <div class="hero-copy site-container relative flex min-h-[520px] md:min-h-[640px] flex-col justify-center py-16 text-paper">
                <div class="eyebrow text-paper/70">{{ $hero?->title }} · {{ $hero?->subtitle }}</div>
                <h1 class="mt-6 max-w-3xl text-5xl md:text-7xl">
                    Свежесть,<br>которая<br><span class="text-neon">включает.</span>
                </h1>
                <p class="mt-6 max-w-md text-sm text-paper/80 leading-relaxed">
                    Ощущение чистоты и лёгкости, с которого приятно начинать день — или начинать заново.
                </p>
                <div class="mt-8">
                    <a href="{{ route('catalog.index', ['line' => 'fresh']) }}" class="btn btn-neon">
                        Смотреть FRESH <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </div>

        {{-- Пять табов: четыре по линейке, пятый по флагу PRO. --}}
        <div class="site-container -mt-px grid grid-cols-2 md:grid-cols-5 border-t border-l border-line bg-paper">
            @foreach ($lines as $i => $line)
                <a href="{{ route('catalog.index', ['line' => $line->code]) }}"
                   class="group flex items-baseline justify-between border-r border-b border-line px-4 py-5 hover:bg-shell">
                    <span class="flex items-baseline gap-3">
                        <span class="text-[10px] text-muted">0{{ $i + 1 }}</span>
                        <span class="text-lg font-black tracking-tight">{{ $line->title }}</span>
                    </span>
                    <span class="text-[10px] text-muted">{{ $line->subtitle }}</span>
                </a>
            @endforeach

            <a href="{{ route('catalog.index', ['tab' => 'pro']) }}"
               class="group flex items-baseline justify-between border-r border-b border-line px-4 py-5 hover:bg-shell">
                <span class="flex items-baseline gap-3">
                    <span class="text-[10px] text-muted">05</span>
                    <span class="text-lg font-black tracking-tight">PRO</span>
                </span>
                <span class="text-[10px] text-muted">профессиональный</span>
            </a>
        </div>
    </section>

    {{-- Каталог-подборка --}}
    <section class="site-container mt-24" data-reveal>
        <div class="eyebrow">Выбирай по ощущениям</div>
        <div class="mt-4 flex flex-wrap items-end justify-between gap-6">
            <h2 class="max-w-xl text-4xl md:text-5xl">Полка, с которой<br>легко начать.</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('catalog.index') }}" class="chip chip-active">Все товары</a>
                <a href="{{ route('catalog.index', ['tab' => 'best']) }}" class="chip">Бестселлеры</a>
                <a href="{{ route('catalog.index', ['tab' => 'new']) }}" class="chip">Новинки</a>
                <a href="{{ route('catalog.index', ['tab' => 'pro']) }}" class="chip">PRO</a>
            </div>
        </div>

        <div class="reveal-stagger mt-10 grid gap-4 grid-cols-2 lg:grid-cols-4">
            @foreach ($products as $i => $product)
                <x-product-card :product="$product" style="--index: {{ $i }}" />
            @endforeach
        </div>

        <div class="mt-10 flex justify-end">
            <a href="{{ route('catalog.index') }}" class="btn btn-dark">
                Показать весь каталог <span aria-hidden="true">→</span>
            </a>
        </div>
    </section>

    {{-- Ты и LIVSI: подборка изображений из админки (видео решено не делать) --}}
    @if ($ugc->isNotEmpty())
        <section class="site-container mt-24" data-reveal>
            <h2 class="text-4xl md:text-5xl">Ты и LIVSI.</h2>
            <div class="mt-8 grid gap-3 grid-cols-2 md:grid-cols-5">
                @foreach ($ugc as $item)
                    <x-img :path="$item->image_path" :alt="$item->alt"
                           sizes="(min-width: 768px) 20vw, 50vw"
                           class="aspect-[4/5] w-full object-cover" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Отзывы: блок собран по макету, состав правится в админке --}}
    @include('partials.reviews')

    {{-- FAQ --}}
    @if ($faq->isNotEmpty())
        <section class="site-container mt-24" data-reveal>
            <div class="grid gap-12 lg:grid-cols-[1fr_1.6fr]">
                <div>
                    <h2 class="text-4xl md:text-5xl">Всё важное —<br>до оформления.</h2>
                    <p class="mt-5 max-w-xs text-xs text-muted leading-relaxed">
                        Ответы на вопросы о доставке, выборе средств и профессиональном уходе.
                    </p>
                </div>

                {{-- Раскрытие плавное, как в прототипе. Оставлен <details>:
                     без JavaScript он открывается сразу, но работает, а плавность
                     добавляет скрипт. Высоту он измеряет по содержимому —
                     в прототипе max-height задан числом, и длинный ответ обрезается. --}}
                <div class="divide-y divide-line border-t border-line" data-faq>
                    @foreach ($faq as $i => $item)
                        <details class="faq-item group py-5">
                            <summary class="flex cursor-pointer items-center justify-between gap-6 list-none">
                                <span class="flex items-baseline gap-5">
                                    <span class="text-[10px] text-muted">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="text-sm font-bold">{{ $item->question }}</span>
                                </span>
                                <i class="text-lg text-muted" aria-hidden="true">+</i>
                            </summary>

                            <div class="faq-answer" data-faq-answer>
                                <p class="mt-4 max-w-2xl pl-10 text-xs text-muted leading-relaxed">{{ $item->answer }}</p>
                            </div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection