@extends('layouts.app')

@section('title', 'Контакты — LIVSI')
@section('description', 'Как связаться с LIVSI: почта, телефон, мессенджеры и реквизиты продавца.')

@php
    use App\Models\Setting;

    $workHours = Setting::get('work_hours');
    $telegram  = Setting::get('telegram_url');
    $whatsapp  = Setting::get('whatsapp_url');
    $email     = $seller?->email ?: Setting::get('manager_email');
@endphp

@section('content')
    <section>
        <div class="site-container page-hero page-hero-narrow">
            <div class="page-hero-copy">
            <div class="eyebrow">Связь</div>
            <h1 class="page-hero-title">Контакты</h1>
            <p class="page-hero-lede">
                Ответим на вопросы о заказе, подберём средства и вышлем оптовый прайс-лист.
            </p>

            @if ($workHours)
                <div class="model-line">
                    <span>Рабочее время</span>
                    <b aria-hidden="true">→</b>
                    <span>{{ $workHours }}</span>
                </div>
            @endif
            </div>
        </div>
    </section>

    <section class="site-container grid gap-12 py-16 md:grid-cols-2 md:gap-[clamp(55px,8vw,125px)] md:py-24">
        <div>
            <div class="eyebrow">Как написать</div>
            <h2 class="section-title mt-6">Напишите<br>нам</h2>

            <ul class="mt-10 divide-y divide-line border-t border-ink">
                @if ($email)
                    <li class="flex items-center justify-between gap-6 py-5">
                        <span class="text-[10px] uppercase tracking-[0.08em] text-muted">Электронная почта</span>
                        <a href="mailto:{{ $email }}" class="text-sm font-bold hover:text-green">{{ $email }}</a>
                    </li>
                @endif

                @if ($seller?->phone)
                    <li class="flex items-center justify-between gap-6 py-5">
                        <span class="text-[10px] uppercase tracking-[0.08em] text-muted">Телефон</span>
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $seller->phone) }}" class="text-sm font-bold hover:text-green">
                            {{ $seller->phone }}
                        </a>
                    </li>
                @endif

                @foreach (['Telegram' => $telegram, 'WhatsApp' => $whatsapp] as $label => $url)
                    @if ($url)
                        <li class="flex items-center justify-between gap-6 py-5">
                            <span class="text-[10px] uppercase tracking-[0.08em] text-muted">{{ $label }}</span>
                            <a href="{{ $url }}" target="_blank" rel="noopener"
                               class="text-sm font-bold hover:text-green">Написать</a>
                        </li>
                    @endif
                @endforeach
            </ul>

            {{-- Пустое состояние честнее выдуманных контактов: адрес почты
                 и ссылки на мессенджеры заполняются в настройках админки. --}}
            @unless ($email || $seller?->phone || $telegram || $whatsapp)
                <p class="mt-8 text-sm leading-relaxed text-muted">
                    Контакты появятся здесь после заполнения настроек сайта.
                </p>
            @endunless

            <div class="mt-10 flex flex-wrap gap-3">
                <a href="{{ route('partners') }}" class="btn btn-outline">
                    Оптовым партнёрам <span aria-hidden="true">→</span>
                </a>
                <a href="{{ route('contract') }}" class="btn btn-outline">
                    Контрактное производство <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>

        <div class="self-start md:border-l md:border-line md:pl-8">
            <div class="eyebrow">Реквизиты</div>
            <h2 class="section-title mt-6">Кто<br>продавец</h2>

            @if ($seller)
                <dl class="legal-requisites mt-10">
                    @foreach ([
                        'Наименование'  => $seller->legal_name,
                        'ИНН'           => $seller->inn,
                        'КПП'           => $seller->kpp,
                        'ОГРН / ОГРНИП' => $seller->ogrn,
                        'Адрес'         => $seller->address,
                    ] as $label => $value)
                        @if (filled($value))
                            <div>
                                <dt>{{ $label }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            @else
                <p class="mt-10 text-sm leading-relaxed text-muted">
                    Реквизиты будут указаны здесь до начала приёма заказов.
                </p>
            @endif

            <p class="mt-8 text-xs leading-relaxed text-muted">
                Условия покупки — в <a href="{{ route('legal.offer') }}" class="underline hover:text-ink">публичной оферте</a>.
                Обработка данных — в <a href="{{ route('legal.privacy') }}" class="underline hover:text-ink">политике конфиденциальности</a>.
            </p>
        </div>
    </section>
@endsection
