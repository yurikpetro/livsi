@extends('layouts.app')

@section('title', 'Декларации соответствия — LIVSI')
@section('description', 'Актуальные декларации соответствия на продукцию LIVSI с возможностью скачать документ в формате PDF.')

@section('content')
    <section class="site-container py-12 md:py-20">
        <div class="eyebrow">Документы на продукцию</div>
        <h1 class="mt-4 text-4xl md:text-6xl">Декларации<br>соответствия</h1>
        <p class="mt-6 max-w-xl text-sm leading-relaxed text-muted">
            Здесь собраны актуальные декларации соответствия на продукцию LIVSI.
            Каждый документ можно открыть и скачать в формате PDF.
        </p>
    </section>

    <section class="site-container pb-16 md:pb-24">
        <div class="flex flex-wrap items-baseline justify-between gap-4 border-b border-line pb-4">
            <h2 class="text-lg md:text-2xl">Документы по товарам</h2>
            @if ($declarations->isNotEmpty())
                <span class="text-[11px] uppercase tracking-[0.08em] text-muted">
                    {{ \App\Support\Plural::ru($declarations->count(), 'документ', 'документа', 'документов') }}
                </span>
            @endif
        </div>

        @if ($declarations->isEmpty())
            {{-- Пустое состояние честное: файлов ещё нет. Обещать «скоро» без
                 сроков не будем, но и делать вид, что документы есть, нельзя. --}}
            <div class="border border-line px-6 py-16 text-center md:py-24">
                <div class="mx-auto grid h-12 w-12 place-items-center border border-line text-[10px] font-bold tracking-[0.08em] text-muted">
                    PDF
                </div>
                <p class="mt-6 text-lg font-bold">Документы готовятся к публикации</p>
                <p class="mx-auto mt-3 max-w-md text-xs leading-relaxed text-muted">
                    После загрузки файлов здесь появятся наименования товаров, номера деклараций,
                    сроки действия и кнопки скачивания. Если документ нужен прямо сейчас —
                    напишите нам, пришлём по запросу.
                </p>
                <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-8">
                    Перейти в каталог <span aria-hidden="true">→</span>
                </a>
            </div>
        @else
            <ul class="divide-y divide-line border-b border-line">
                @foreach ($declarations as $declaration)
                    <li class="grid gap-3 py-5 md:grid-cols-[1fr_auto] md:items-center md:gap-8">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold">{{ $declaration->number }}</span>
                                @if ($declaration->isExpired())
                                    {{-- Истёкшую декларацию не выдаём за действующую. --}}
                                    <span class="bg-shell px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-danger">
                                        Срок истёк
                                    </span>
                                @endif
                            </div>

                            @if ($declaration->title)
                                <p class="mt-1 text-xs text-muted">{{ $declaration->title }}</p>
                            @endif

                            @if ($declaration->products->isNotEmpty())
                                <p class="mt-2 text-xs leading-relaxed">
                                    @foreach ($declaration->products as $product)
                                        <a href="{{ route('catalog.show', $product) }}" class="underline hover:text-green">{{ $product->title }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif

                            <p class="mt-2 text-[11px] text-muted">
                                @if ($declaration->issued_on)
                                    Выдана {{ $declaration->issued_on->format('d.m.Y') }}
                                @endif
                                @if ($declaration->valid_until)
                                    · действует до {{ $declaration->valid_until->format('d.m.Y') }}
                                @endif
                            </p>
                        </div>

                        <div class="md:justify-self-end">
                            @if ($declaration->hasFile())
                                <a href="{{ asset($declaration->file_path) }}" target="_blank" rel="noopener"
                                   class="btn btn-outline !min-h-10 !px-4 !text-[10px]">
                                    Скачать PDF
                                    @if ($size = $declaration->fileSizeForHumans())
                                        <span class="font-normal opacity-60">{{ $size }}</span>
                                    @endif
                                </a>
                            @else
                                <span class="text-[10px] uppercase tracking-[0.08em] text-muted">Файл не загружен</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
