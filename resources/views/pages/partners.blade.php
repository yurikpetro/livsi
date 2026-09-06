@extends('layouts.app')

@section('title', 'Стать партнёром LIVSI — оптовое сотрудничество')
@section('description', 'Закупайте косметику LIVSI по оптовым ценам и продавайте её в магазине, салоне, студии или онлайн. Менеджер отправит актуальный прайс-лист.')

@php
    use App\Models\LeadRequest;
    use App\Models\Setting;

    // Этапы — как в прототипе: три шага от прайса до продаж.
    $steps = [
        ['Получаете прайс-лист', 'С актуальными оптовыми ценами на продукцию.'],
        ['Формируете заказ', 'Менеджер поможет подобрать ассортимент под ваш формат.'],
        ['Продаёте у себя', 'В магазине, салоне, студии, онлайн или на маркетплейсах.'],
    ];

    $sent = session('lead_sent') || request()->boolean('sent');
@endphp

@section('content')
    {{-- ─────────────────────────────────────────────── первый экран --}}
    <section>
        <div class="site-container page-hero">
            <div class="page-hero-copy">
                <div class="eyebrow">Оптовое сотрудничество</div>

                <h1 class="page-hero-title">
                    Станьте<br>партнёром<br>LIVSI
                </h1>

                <p class="page-hero-lede">
                    Закупайте продукцию LIVSI по оптовым ценам и продавайте её своим
                    клиентам в магазине, салоне, студии или онлайн.
                </p>

                <div class="model-line">
                    <span>Опт</span>
                    <b aria-hidden="true">→</b>
                    <span>Розница</span>
                </div>

                <a href="#partner-form" class="btn btn-dark page-hero-cta">
                    Получить прайс-лист
                    <span aria-hidden="true">↓</span>
                </a>
            </div>

            <x-photo-slot path="img/partners/wholesale.jpg"
                          alt="Оптовая поставка косметики LIVSI"
                          number="01"
                          caption="Получаете продукцию оптом"
                          placeholder="Фото оптовой поставки"
                          sizes="(min-width: 768px) 50vw, 100vw"
                          :priority="true" />
        </div>
    </section>

    {{-- ─────────────────────────────────────────────── условия --}}
    <section class="site-container py-16 md:py-24" data-reveal>
        <div class="grid gap-[42px] md:grid-cols-2 md:gap-[clamp(55px,8vw,125px)]">
            <x-photo-slot path="img/partners/retail.jpg"
                          alt="Косметика LIVSI на витрине магазина"
                          number="02"
                          caption="Продаёте LIVSI у себя"
                          placeholder="Фото витрины"
                          sizes="(min-width: 768px) 50vw, 100vw"
                          class="order-2 md:order-1" />

            <div class="order-1 self-center md:order-2">
                <div class="eyebrow">Условия сотрудничества</div>
                <h2 class="section-title mt-6">Как это<br>работает</h2>
                <p class="section-lede">
                    Актуальные цены и условия менеджер отправит вместе с оптовым прайс-листом.
                </p>

                <ol class="step-list">
                    @foreach ($steps as $i => [$title, $text])
                        <li>
                            <span>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <h3>{{ $title }}</h3>
                                <p>{{ $text }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────────────── заявка --}}
    <section id="partner-form" class="lead-section scroll-mt-24">
<div class="site-container lead-layout py-16 md:py-24">
            <div>
                <div><span class="lead-eyebrow">Заявка для партнёров</span></div>
                <h2 class="lead-title">Получить<br>прайс-лист</h2>
                <p class="lead-lede">
                    Оставьте контакты. Менеджер свяжется с вами и отправит актуальные
                    оптовые цены.
                </p>
            </div>

            <div>
                @if ($sent)
                    {{-- Подтверждение отправки: в прототипе такого состояния нет,
                         без него не видно, ушла ли заявка. --}}
                    <div class="border-l-4 border-green bg-paper p-6 md:p-8" role="status">
                        <h3 class="text-xl normal-case tracking-tight">Заявка отправлена</h3>
                        <p class="mt-3 text-sm leading-relaxed text-muted">
                            Менеджер свяжется с вами в рабочее время
                            @if ($workHours = Setting::get('work_hours'))
                                ({{ $workHours }})
                            @endif
                            и отправит прайс-лист.
                        </p>
                        <a href="{{ route('catalog.index') }}" class="btn btn-outline mt-6">
                            В каталог
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                @else
                    <form method="POST" action="{{ route('partners.store') }}" class="lead-form">
                        @csrf

                        @error('company_website')
                            <p class="field-error" role="alert">{{ $message }}</p>
                        @enderror

                        <label class="field">
                            <span class="field-label">Ваше имя <span aria-hidden="true">*</span></span>
                            <input type="text" name="name" value="{{ old('name') }}"
                                   placeholder="Как к вам обращаться"
                                   autocomplete="name" required
                                   @error('name') aria-invalid="true" aria-describedby="err-name" @enderror
                                   class="field-control">
                            @error('name')
                                <span class="field-error" id="err-name">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="field">
                            <span class="field-label">Телефон или Telegram <span aria-hidden="true">*</span></span>
                            <input type="text" name="contact" value="{{ old('contact') }}"
                                   placeholder="Удобный способ связи"
                                   autocomplete="tel" required
                                   @error('contact') aria-invalid="true" aria-describedby="err-contact" @enderror
                                   class="field-control">
                            @error('contact')
                                <span class="field-error" id="err-contact">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="field">
                            <span class="field-label">Город</span>
                            <input type="text" name="city" value="{{ old('city') }}"
                                   placeholder="Где вы работаете"
                                   autocomplete="address-level2"
                                   class="field-control">
                            @error('city')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="field">
                            <span class="field-label">Формат продаж</span>
                            <select name="sales_format" class="field-control">
                                <option value="">Выберите вариант</option>
                                @foreach (LeadRequest::SALES_FORMATS as $code => $label)
                                    <option value="{{ $code }}" @selected(old('sales_format') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('sales_format')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </label>

                        {{-- Поля для комментария в прототипе нет. Добавлено:
                             партнёру почти всегда есть что уточнить про объёмы
                             и ассортимент, а иначе это выясняется звонком с нуля. --}}
                        <label class="field field-wide">
                            <span class="field-label">Комментарий</span>
                            <textarea name="comment" rows="3"
                                      placeholder="Что интересует, какие объёмы, есть ли опыт работы с косметикой"
                                      class="field-control">{{ old('comment') }}</textarea>
                            @error('comment')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </label>

                        {{-- Приманка для ботов. Скрыто через aria-hidden и вынос за экран,
                             а не display:none — часть ботов такие поля пропускает. --}}
                        <div aria-hidden="true" class="absolute left-[-9999px] h-0 w-0 overflow-hidden">
                            <label>
                                Сайт компании
                                <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                            </label>
                        </div>

                        {{-- Два раздельных согласия, оба без предзаполнения.
                             Объединять их одной галочкой нельзя: обработка данных
                             нужна, чтобы ответить на заявку, а рассылка —
                             самостоятельная цель с добровольным согласием. --}}
                        <label class="consent">
                            <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                            <span>
                                Согласен на <a href="{{ route('legal.consent') }}" target="_blank" class="underline hover:text-ink">обработку персональных данных</a>
                                и принимаю <a href="{{ route('legal.privacy') }}" target="_blank" class="underline hover:text-ink">политику конфиденциальности</a>
                                @error('consent')
                                    <span class="field-error mt-1 block">{{ $message }}</span>
                                @enderror
                            </span>
                        </label>

                        <label class="consent">
                            <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent'))>
                            <span>
                                Согласен получать новости и предложения LIVSI — по желанию,
                                на приём заявки не влияет
                                @error('marketing_consent')
                                    <span class="field-error mt-1 block">{{ $message }}</span>
                                @enderror
                            </span>
                        </label>

                        <button type="submit" class="btn btn-dark">
                            Отправить заявку
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
