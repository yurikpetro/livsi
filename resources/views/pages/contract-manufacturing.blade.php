@extends('layouts.app')

@section('title', 'Контрактное производство косметики LIVSI')

@section('content')
    <section class="site-container py-20">
        <div class="eyebrow">Заявка на производство</div>
        <h1 class="mt-5 max-w-2xl text-5xl md:text-6xl">Обсудить<br>проект</h1>
        <p class="mt-6 max-w-md text-sm text-muted leading-relaxed">
            Выпускаем косметику под вашим брендом. Оставьте контакты и коротко опишите
            задачу — менеджер свяжется и уточнит детали.
        </p>
        {{-- Форма с собственным набором полей (категория продукта, планируемый объём) —
             следующий инкремент. См. docs/07-design-review.md §8.2. --}}
        <p class="mt-10 text-xs text-muted">Форма заявки подключается в следующем инкременте.</p>
    </section>
@endsection