@extends('layouts.app')

{{-- Главная — то, что Яндекс показывает по запросу «livsi»: в заголовке бренд и слово «официальный». --}}
@section('title', 'LIVSI — официальный интернет-магазин косметики LIVSI PROFESSIONAL')
@section('description', 'Официальный сайт LIVSI (ЛИВСИ): косметика для дома и профессионалов — уход, который чувствует тебя. Доставка по всей России.')

@push('head')
    @php
        $root = rtrim((string) config('app.url'), '/');
        $sameAs = array_values(array_filter(array_map(fn (string $key) => \App\Models\Setting::get($key) ?: null,
            ['vk_url', 'telegram_url', 'wildberries_url', 'ozon_url'])));
        $organization = array_filter([
            '@type' => 'Organization',
            '@id' => $root.'/#organization',
            'name' => 'LIVSI',
            'alternateName' => ['LIVSI PROFESSIONAL', 'ЛИВСИ', 'Ливси'],
            'url' => $root.'/',
            'logo' => asset('img/logo.svg'),
            'email' => $siteSeller?->email ?: null,
            'telephone' => $siteSeller?->phone ?: null,
            'sameAs' => $sameAs ?: null,
        ]);
        $schema = ['@context' => 'https://schema.org', '@graph' => [
            $organization,
            ['@type' => 'WebSite', '@id' => $root.'/#website', 'url' => $root.'/', 'name' => 'LIVSI', 'alternateName' => 'ЛИВСИ',
                'inLanguage' => 'ru-RU', 'publisher' => ['@id' => $root.'/#organization']],
        ]];
    @endphp
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    @php
        // Баннеры правятся в админке («Баннеры на главной»).
        $slides = \App\Models\HeroSlide::query()->shown()->get();
    @endphp
    @if ($slides->isNotEmpty())
    <section class="hero relative overflow-hidden bg-shell" data-hero id="lines" aria-label="Линейки LIVSI">
        @foreach ($slides as $slide)
            @php
                [$code, $first, $second, $third] = [$slide->code, $slide->title_first, $slide->title_second, $slide->title_accent];
                [$description, $aroma, $color] = [$slide->description, $slide->aroma, $slide->accent_color];
            @endphp
            <div data-hero-slide="{{ $code }}" @if (! $loop->first) hidden @endif style="--slide-accent: {{ $color }}">
                <div class="hero-photo" style="--focus: {{ $slide->desktopFocus() }}; --focus-m: {{ $slide->mobileFocus() }}">
                    <x-hero-img :slide="$slide" :loading="$loop->first ? 'eager' : 'lazy'" :fetchpriority="$loop->first ? 'high' : 'auto'" />
                </div>
                <div class="hero-shade absolute inset-0"></div>
                <div class="hero-copy site-container relative flex flex-col justify-center text-paper" id="hero-{{ $code }}">
                    @if ($loop->first)
                        <h1 class="hero-title">{{ $first }}<span class="hero-title-middle">{{ $second }}</span><span class="hero-title-accent">{{ $third }}</span></h1>
                    @else
                        <h2 class="hero-title">{{ $first }}<span class="hero-title-middle">{{ $second }}</span><span class="hero-title-accent">{{ $third }}</span></h2>
                    @endif
                    <p class="hero-description">{{ $description }}</p>
                    <div class="hero-aroma"><span class="eyebrow">{{ $code === 'pro' ? 'Профессиональный подход' : 'Характер аромата' }}</span><b>{{ $aroma }}</b></div>
                    <div @class(['hero-actions flex flex-wrap', 'gap-3' => $code !== 'pro', 'hero-pro-actions' => $code === 'pro'])>
                        @if ($code === 'pro')
                            <a href="{{ route('catalog.index', ['line' => 'pro-skin']) }}" class="btn hero-cta hero-pro-cta">PRO SKIN <span aria-hidden="true">↗</span></a>
                            <a href="{{ route('catalog.index', ['line' => 'pro-manicure-pedicure']) }}" class="btn hero-cta hero-pro-cta hero-pro-cta-secondary">PRO MANICURE / PEDICURE <span aria-hidden="true">↗</span></a>
                        @else
                            <a href="{{ route('catalog.index', ['line' => $code]) }}" class="btn hero-cta">Смотреть {{ strtoupper($code) }} <span aria-hidden="true">→</span></a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
        <nav class="site-container hero-lines" aria-label="Выбор линии LIVSI">
            @foreach ($slides as $slide)
                <button type="button" data-hero-select="{{ $slide->code }}" aria-controls="hero-{{ $slide->code }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" style="--slide-accent: {{ $slide->accent_color }}">
                    <span><small>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</small><strong>{{ mb_strtoupper($slide->code) }}</strong></span><em>{{ $slide->tab_label }}</em>
                </button>
            @endforeach
        </nav>
    </section>
    @endif

    <section class="site-container mt-24" data-reveal data-product-shelf>
        <div class="eyebrow">Выбирай по ощущениям</div>
        <div class="mt-4 flex flex-wrap items-end justify-between gap-6">
            <div><h2 class="section-title">Полка, с которой<br>легко начать.</h2><p class="shelf-description mt-5 max-w-md text-muted">Уход, который хочется повторять. Найди свою формулу, аромат и привычный ритуал.</p></div>
            <div class="shelf-filters" role="group" aria-label="Фильтр товаров">
                @foreach (['best' => 'Бестселлеры', 'new' => 'Новинки', 'all' => 'Все товары'] as $code => $label)
                    <button type="button" data-shelf-filter="{{ $code }}" aria-pressed="{{ $code === 'all' ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="shelf-wrap mt-10">
            <button type="button" class="shelf-arrow shelf-prev" data-shelf-prev aria-label="Показать предыдущие товары" disabled>←</button>
            <div class="product-shelf reveal-stagger" data-shelf-track tabindex="0" aria-label="Подборка товаров">
                @foreach ($products as $i => $product)
                    <x-product-card :product="$product" :data-shelf-line="$product->isProfessional() ? 'pro' : ($product->line?->code ?? '')" :data-badge="$product->badge" style="--index: {{ $i }}" />
                @endforeach
            </div>
            <button type="button" class="shelf-arrow shelf-next" data-shelf-next aria-label="Показать следующие товары">→</button>
        </div>
        <p data-shelf-empty hidden class="py-10 text-sm text-muted">В этой подборке пока нет товаров.</p>
        <div class="mt-8 flex justify-end"><a href="{{ route('catalog.index') }}" class="btn btn-dark">Показать весь каталог <span aria-hidden="true">→</span></a></div>
    </section>

    @if ($ugc->isNotEmpty())
        <section class="site-container mt-24" data-reveal aria-labelledby="ugc-title">
            <h2 class="section-title" id="ugc-title">Ты и LIVSI.</h2>
            <div class="ugc-track mt-8" aria-label="Галереи LIVSI">
                @foreach ($ugc as $item)
                    @php($media = $item->galleryMedia())
                    <article class="ugc-card" data-ugc-card>
                        <button type="button" class="ugc-open" data-ugc-open aria-haspopup="dialog" aria-controls="ugc-viewer-{{ $item->id }}" aria-label="Открыть галерею: {{ $item->alt ?: 'LIVSI' }}">
                            <x-img :path="$item->image_path" :alt="$item->alt" sizes="(min-width: 1024px) 20vw, 70vw" class="ugc-cover" />
                            <span class="ugc-launch" aria-hidden="true">▶</span>
                        </button>
                        <x-ugc-product :item="$item" />
                        <dialog class="ugc-modal" id="ugc-viewer-{{ $item->id }}" data-ugc-modal aria-labelledby="ugc-caption-{{ $item->id }}">
                            <div class="ugc-viewer" data-ugc-gallery>
                                <button type="button" class="ugc-modal-close" data-ugc-close aria-label="Закрыть галерею">×</button>
                                <div class="ugc-media-track" data-ugc-track tabindex="0" aria-label="Фото и видео: {{ $item->alt ?: 'LIVSI' }}">
                                    @foreach ($media as $frame)
                                        <div class="ugc-slide" data-ugc-slide role="group" aria-label="Кадр {{ $loop->iteration }} из {{ count($media) }}">
                                            @if ($frame['type'] === 'video')
                                                <div class="ugc-player" x-data="{ playing: false, failed: false }">
                                                    <video x-ref="video" preload="none" playsinline loop poster="{{ asset($item->image_path) }}" x-on:pause="playing = false" x-on:play="playing = true" x-on:error="failed = true" aria-label="{{ $item->alt ?: 'Ролик LIVSI' }}">
                                                        <source src="{{ asset($frame['path']) }}" type="{{ str_ends_with(strtolower($frame['path']), '.webm') ? 'video/webm' : 'video/mp4' }}">
                                                    </video>
                                                    <button type="button" class="ugc-play" x-show="!playing && !failed" x-on:click="$refs.video.play().catch(() => { failed = true })" aria-label="Смотреть ролик: {{ $item->alt ?: 'LIVSI' }}"><span aria-hidden="true">▶</span></button>
                                                    <p class="ugc-error" x-show="failed" x-cloak>Не удалось воспроизвести ролик. <a href="{{ asset($frame['path']) }}" class="underline">Открыть видео</a></p>
                                                </div>
                                            @else
                                                <x-img :path="$frame['path']" :alt="$item->alt" sizes="(min-width: 1024px) 20vw, 70vw" class="ugc-cover" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if (count($media) > 1)
                                    <span class="ugc-counter" data-ugc-counter aria-live="polite">1 / {{ count($media) }}</span>
                                    <button type="button" class="ugc-arrow ugc-prev" data-ugc-prev aria-label="Предыдущий кадр: {{ $item->alt ?: 'LIVSI' }}" disabled>←</button>
                                    <button type="button" class="ugc-arrow ugc-next" data-ugc-next aria-label="Следующий кадр: {{ $item->alt ?: 'LIVSI' }}">→</button>
                                @endif
                                <h3 class="ugc-modal-caption" id="ugc-caption-{{ $item->id }}">{{ $item->alt ?: 'Ты и LIVSI' }}</h3>
                                <x-ugc-product :item="$item" />
                            </div>
                        </dialog>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Отзывы: блок собран по макету, состав правится в админке --}}
    @include('partials.reviews')

    {{-- FAQ --}}
    @if ($faq->isNotEmpty())
        <section class="site-container mt-24 home-faq" id="faq" data-reveal>
            <div class="grid gap-12 lg:grid-cols-[1fr_1.6fr]">
                <div>
                    <div class="eyebrow mb-5">Вопросы и ответы</div>
                    <h2 class="section-title">Всё важное —<br>до оформления.</h2>
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
                        <details class="faq-item group py-5" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer items-center justify-between gap-6 list-none">
                                <span class="flex items-baseline gap-5">
                                    <span class="text-[10px] text-muted">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="text-sm font-bold">{{ $item->question }}</span>
                                </span>
                                <i class="faq-symbol text-lg text-muted" aria-hidden="true"></i>
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
