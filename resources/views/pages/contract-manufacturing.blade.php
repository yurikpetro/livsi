@extends('layouts.app')

@section('title', 'Контрактное производство косметики — LIVSI')
@section('description', 'Выпускаем косметику под вашим брендом на собственной производственной базе: от запроса и согласования состава до готового тиража.')

@php
    use App\Models\LeadRequest;
    use App\Models\Setting;

    // Этапы работы — как в прототипе: три шага от запроса до тиража.
    $steps = [
        ['Получаем запрос', 'Уточняем тип продукта, нужные свойства, формат и объём.'],
        ['Согласовываем продукт', 'Определяем состав, внешний вид и формат будущей косметики.'],
        ['Производим тираж', 'Выпускаем продукт на нашей площадке под вашим брендом.'],
    ];

    // Фотографий производства пока нет — заказчик их не присылал. Слот
    // остаётся в вёрстке и подхватит файлы, как только они появятся.
    $shots = [
        ['path' => 'img/contract/production.jpg', 'alt' => 'Производство косметики LIVSI', 'badge' => 'Производим на своей базе'],
        ['path' => 'img/contract/branding.jpg',   'alt' => 'Косметика под брендом клиента', 'badge' => 'Выпускаем под вашим брендом'],
    ];

    $sent = session('lead_sent') || request()->boolean('sent');
@endphp

@section('content')
    {{-- ─────────────────────────────────────────────── первый экран --}}
    <section class="bg-shell">
        <div class="site-container grid items-stretch gap-10 py-16 md:grid-cols-2 md:gap-14 md:py-24">
            <div class="flex flex-col">
                <div class="eyebrow">Контрактное производство</div>

                <h1 class="mt-5 text-[2.75rem] leading-[0.92] sm:text-6xl lg:text-7xl">
                    Косметика<br>под вашим<br>брендом
                </h1>

                <p class="mt-7 max-w-md text-sm leading-relaxed text-muted">
                    На базе собственного производства LIVSI создадим косметику по вашему
                    запросу — с вашим названием и для ваших клиентов.
                </p>

                <div class="mt-10 flex items-center justify-between border-y border-line py-4 text-[10px] font-bold uppercase tracking-[0.12em]">
                    <span>Ваша идея</span>
                    <span aria-hidden="true" class="text-muted">→</span>
                    <span>Готовый продукт</span>
                </div>

                <a href="#contract-request" class="btn btn-dark mt-auto w-full max-w-xs">
                    Обсудить проект
                    <span aria-hidden="true">↓</span>
                </a>
            </div>

            <x-photo-slot :path="$shots[0]['path']" :alt="$shots[0]['alt']"
                          :badge="$shots[0]['badge']"
                          placeholder="Фото производства"
                          tint="bg-paper"
                          sizes="(min-width: 768px) 50vw, 100vw"
                          :priority="true"
                          class="min-h-64" />
        </div>
    </section>

    {{-- ─────────────────────────────────────────────── этапы --}}
    <section class="site-container py-16 md:py-24">
        <div class="grid gap-10 md:grid-cols-2 md:gap-14">
            <x-photo-slot :path="$shots[1]['path']" :alt="$shots[1]['alt']"
                          :badge="$shots[1]['badge']"
                          badge-class="bg-paper"
                          placeholder="Фото продукции под брендом клиента"
                          tint="bg-shell"
                          sizes="(min-width: 768px) 50vw, 100vw"
                          class="order-2 min-h-64 md:order-1" />

            <div class="order-1 md:order-2">
                <div class="eyebrow">Этапы проекта</div>
                <h2 class="mt-4 text-4xl md:text-5xl">Как мы<br>работаем</h2>
                <p class="mt-5 max-w-md text-sm leading-relaxed text-muted">
                    Начинаем с вашей задачи и последовательно доводим её до готового продукта.
                </p>

                <ol class="mt-10 divide-y divide-line border-t border-line">
                    @foreach ($steps as $i => [$title, $text])
                        <li class="flex gap-5 py-6">
                            <span class="shrink-0 text-xs font-black text-green">
                                {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                            </span>
                            <div>
                                <h3 class="text-base normal-case tracking-normal">{{ $title }}</h3>
                                <p class="mt-2 text-xs leading-relaxed text-muted">{{ $text }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────────────── заявка --}}
    <section id="contract-request" class="bg-shell scroll-mt-24">
        <div class="site-container grid gap-10 py-16 md:grid-cols-2 md:gap-16 md:py-24">
            <div>
                <div class="eyebrow">Заявка на производство</div>
                <h2 class="mt-4 text-4xl md:text-5xl">Обсудить<br>проект</h2>
                <p class="mt-5 max-w-md text-sm leading-relaxed text-muted">
                    Оставьте контакты и коротко опишите задачу. Менеджер свяжется
                    с вами и уточнит детали.
                </p>
            </div>

            <div>
                @if ($sent)
                    {{-- Подтверждение отправки: в прототипе такого экрана нет,
                         но без него человек не понимает, ушла ли заявка. --}}
                    <div class="border-l-4 border-green bg-paper p-6 md:p-8" role="status">
                        <h3 class="text-xl normal-case tracking-tight">Заявка отправлена</h3>
                        <p class="mt-3 text-sm leading-relaxed text-muted">
                            Менеджер свяжется с вами в рабочее время
                            @if ($workHours = Setting::get('work_hours'))
                                ({{ $workHours }})
                            @endif
                            и уточнит детали проекта.
                        </p>
                        <a href="{{ route('catalog.index') }}" class="btn btn-outline mt-6">
                            В каталог
                            <span aria-hidden="true">→</span>
                        </a>
                    </div>
                @else
                    <form method="POST" action="{{ route('contract.store') }}" class="space-y-5">
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
                            <span class="field-label">Какой продукт нужен</span>
                            <select name="product_category" class="field-control">
                                <option value="">Выберите категорию</option>
                                @foreach (LeadRequest::CONTRACT_CATEGORIES as $code => $label)
                                    <option value="{{ $code }}" @selected(old('product_category') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('product_category')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="field">
                            <span class="field-label">Планируемый объём</span>
                            <input type="text" name="planned_volume" value="{{ old('planned_volume') }}"
                                   placeholder="Например, 1 000 единиц"
                                   class="field-control">
                            @error('planned_volume')
                                <span class="field-error">{{ $message }}</span>
                            @enderror
                        </label>

                        {{-- Поля для описания задачи в прототипе нет, хотя текст выше
                             просит «коротко опишите задачу». Добавлено: без него
                             менеджеру придётся выяснять всё звонком с нуля. --}}
                        <label class="field">
                            <span class="field-label">Опишите задачу</span>
                            <textarea name="comment" rows="4"
                                      placeholder="Что за продукт, для кого, есть ли пожелания по составу и упаковке"
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

                        <label class="consent">
                            <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                            <span>
                                Согласен на обработку персональных данных
                                @error('consent')
                                    <span class="field-error mt-1 block">{{ $message }}</span>
                                @enderror
                            </span>
                        </label>

                        <button type="submit" class="btn btn-dark w-full">
                            Отправить заявку
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
