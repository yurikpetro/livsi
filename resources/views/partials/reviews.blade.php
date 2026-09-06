@php
    use App\Models\Setting;

    // Композиция макета жёсткая: большая карточка слева на две строки
    // и две узкие справа. Поэтому обращаемся к местам по имени, а не
    // перебираем список — иначе вёрстка разъедется от смены сортировки.
    $main   = $reviews['main']   ?? null;
    $top    = $reviews['top']    ?? null;
    $bottom = $reviews['bottom'] ?? null;

    $score = Setting::get('reviews_score');
@endphp

@if ($main)
    <section class="reviews bg-shell pt-[72px] pb-[74px] md:pt-[100px] md:pb-[104px]" id="reviews" aria-labelledby="reviews-title" data-reveal>
        <div class="site-container">
            <div class="reviews-head">
                <div>
                    <span class="reviews-eyebrow">{{ Setting::get('reviews_eyebrow', 'Отзывы покупателей') }}</span>
                    <h2 class="reviews-title" id="reviews-title">
                        Не просто нравится.<br><em>Становится ритуалом.</em>
                    </h2>
                </div>

                @if ($score)
                    <div class="reviews-rating">
                        <b>{{ $score }}</b>
                        <p>
                            <span aria-label="Оценка {{ $score }} из 5">★★★★★</span>
                            <strong>{{ Setting::get('reviews_score_caption', 'Средняя оценка') }}</strong>
                            <small>{{ Setting::get('reviews_score_note') }}</small>
                        </p>
                    </div>
                @endif
            </div>

            <div class="reviews-grid reveal-stagger">
                {{-- Большая карточка: цитата крупно, фотография товара выезжает за край --}}
                <article class="review-card review-main {{ $main->accent }}">
                    <div class="review-top">
                        <span>{{ $main->captionLine() }}</span>
                        <b aria-label="Оценка {{ $main->rating }} из 5">{{ $main->stars() }}</b>
                    </div>

                    <blockquote>«{{ $main->text }}»</blockquote>

                    <div class="reviewer">
                        <i aria-hidden="true">{{ $main->initial() }}</i>
                        <p>
                            <b>{{ $main->author }}</b>
                            <small>{{ $main->role_caption }}</small>
                        </p>
                    </div>

                    @if ($main->image_path)
                        <img src="{{ asset($main->image_path) }}" alt="{{ $main->product?->title ?? $main->author }}" loading="lazy">
                    @endif
                </article>

                {{-- Две узкие: текст слева, фотография колонкой справа --}}
                @foreach (array_filter([$top, $bottom]) as $side)
                    <article class="review-card review-side {{ $side->accent }}">
                        <div class="review-side-copy">
                            <div class="review-top">
                                <span>{{ $side->captionLine() }}</span>
                                <b aria-label="Оценка {{ $side->rating }} из 5">{{ $side->stars() }}</b>
                            </div>

                            <blockquote>«{{ $side->text }}»</blockquote>

                            <div class="reviewer">
                                <i aria-hidden="true">{{ $side->initial() }}</i>
                                <p>
                                    <b>{{ $side->author }}</b>
                                    <small>{{ $side->role_caption }}</small>
                                </p>
                            </div>
                        </div>

                        <div class="review-media">
                            @if ($side->image_path)
                                <img src="{{ asset($side->image_path) }}" alt="{{ $side->product?->title ?? $side->author }}" loading="lazy">
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="reviews-foot">
                <a href="{{ route('catalog.index', ['tab' => 'best']) }}">
                    Смотреть товары из отзывов
                    <b aria-hidden="true">→</b>
                </a>
            </div>
        </div>
    </section>
@endif
