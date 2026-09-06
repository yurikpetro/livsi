@extends('layouts.app')

@section('title', ($page->seo_title ?: $page->title) . ' — LIVSI')
@section('description', $page->seo_description ?: $page->lede)

@section('content')
    <section>
        <div class="site-container page-hero page-hero-narrow">
            <div class="page-hero-copy">
            <div class="eyebrow">Документы</div>
            <h1 class="page-hero-title">{{ $page->title }}</h1>

            @if ($page->lede)
                <p class="page-hero-lede">{{ $page->lede }}</p>
            @endif

            {{-- Дата редакции — часть документа: оферта без неё спорна,
                 потому что непонятно, какие условия действовали при заказе. --}}
            <p class="mt-6 text-[11px] uppercase tracking-[0.08em] text-muted">
                Редакция от {{ $page->updated_at->translatedFormat('j F Y') }}
            </p>
            </div>
        </div>
    </section>

    <section class="site-container grid gap-12 py-16 md:grid-cols-[1fr_18rem] md:gap-[clamp(55px,8vw,125px)] md:py-24">
        {{-- Текст приходит из редактора админки, его пишет заказчик.
             Экранировать нельзя — это размеченный документ. --}}
        <article class="legal-body">
            {!! $page->body !!}

            @if ($seller)
                <h2>Реквизиты продавца</h2>
                <dl class="legal-requisites">
                    @foreach ([
                        'Наименование'           => $seller->legal_name,
                        'ИНН'                    => $seller->inn,
                        'КПП'                    => $seller->kpp,
                        'ОГРН / ОГРНИП'          => $seller->ogrn,
                        'Адрес'                  => $seller->address,
                        'Электронная почта'      => $seller->email,
                        'Телефон'                => $seller->phone,
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
                {{-- Честное состояние вместо выдуманных реквизитов:
                     ошибочный ИНН в оферте хуже, чем его отсутствие. --}}
                <h2>Реквизиты продавца</h2>
                <p>Реквизиты будут указаны здесь до начала приёма заказов.</p>
            @endif
        </article>

        <aside class="md:border-l md:border-line md:pl-8">
            <div class="eyebrow">Остальные документы</div>
            <ul class="mt-5 space-y-3">
                @foreach ($others as $other)
                    <li>
                        <a href="{{ route('legal.' . $other->slug) }}"
                           class="text-sm underline decoration-line underline-offset-4 hover:text-green">
                            {{ $other->menuTitle() }}
                        </a>
                    </li>
                @endforeach
                <li>
                    <a href="{{ route('contacts') }}"
                       class="text-sm underline decoration-line underline-offset-4 hover:text-green">
                        Контакты
                    </a>
                </li>
                <li>
                    <a href="{{ route('declarations') }}"
                       class="text-sm underline decoration-line underline-offset-4 hover:text-green">
                        Декларации соответствия
                    </a>
                </li>
            </ul>
        </aside>
    </section>
@endsection
