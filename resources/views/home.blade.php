@extends('layouts.app')

@section('content')
    @php $hero = $lines->firstWhere('code', 'fresh'); @endphp

    {{-- Hero --}}
    <section class="relative">
        <div class="relative min-h-[520px] md:min-h-[640px] overflow-hidden bg-shell">
            <x-img path="img/catalog/fresh-hero.jpg" alt="" sizes="100vw"
                   loading="eager" fetchpriority="high"
                   class="absolute inset-0 h-full w-full object-cover" />
            <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/30 to-transparent"></div>

            <div class="site-container relative flex min-h-[520px] md:min-h-[640px] flex-col justify-center py-16 text-paper">
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
    <section class="site-container mt-24">
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

        <div class="mt-10 grid gap-4 grid-cols-2 lg:grid-cols-4">
            @foreach ($products as $product)
                <x-product-card :product="$product" />
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
        <section class="site-container mt-24">
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

    {{-- Отзывы: только с указанием площадки-источника --}}
    @if ($reviews->isNotEmpty())
        <section class="bg-shell mt-24 py-20">
            <div class="site-container">
                <div class="eyebrow">Отзывы покупателей на Ozon и Wildberries</div>
                <h2 class="mt-4 max-w-2xl text-4xl md:text-5xl">Не просто нравится.<br>Становится ритуалом.</h2>

                <div class="mt-10 grid gap-4 md:grid-cols-2">
                    @foreach ($reviews as $review)
                        <figure class="bg-paper p-8">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                    {{ $review->product?->line?->title }} @if ($review->product) · {{ $review->product->title }} @endif
                                </span>
                                <span class="text-xs">{{ str_repeat('★', $review->rating) }}</span>
                            </div>
                            <blockquote class="mt-5 text-lg leading-snug">«{{ $review->text }}»</blockquote>
                            <figcaption class="mt-6 text-[10px] uppercase tracking-[0.1em] text-muted">
                                {{ $review->author }} · отзыв с {{ $review->sourceLabel() }}
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- FAQ --}}
    @if ($faq->isNotEmpty())
        <section class="site-container mt-24">
            <div class="grid gap-12 lg:grid-cols-[1fr_1.6fr]">
                <div>
                    <h2 class="text-4xl md:text-5xl">Всё важное —<br>до оформления.</h2>
                    <p class="mt-5 max-w-xs text-xs text-muted leading-relaxed">
                        Ответы на вопросы о доставке, выборе средств и профессиональном уходе.
                    </p>
                </div>

                <div class="divide-y divide-line border-t border-line">
                    @foreach ($faq as $i => $item)
                        <details class="group py-5">
                            <summary class="flex cursor-pointer items-center justify-between gap-6 list-none">
                                <span class="flex items-baseline gap-5">
                                    <span class="text-[10px] text-muted">0{{ $i + 1 }}</span>
                                    <span class="text-sm font-bold">{{ $item->question }}</span>
                                </span>
                                <span class="text-lg text-muted group-open:rotate-45 transition" aria-hidden="true">+</span>
                            </summary>
                            <p class="mt-4 max-w-2xl pl-10 text-xs text-muted leading-relaxed">{{ $item->answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection