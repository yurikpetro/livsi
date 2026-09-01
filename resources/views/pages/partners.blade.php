@extends('layouts.app')

@section('title', 'Стать партнёром LIVSI — оптовое сотрудничество')

@section('content')
    <section class="site-container py-20">
        <div class="eyebrow">Оптовое сотрудничество</div>
        <h1 class="mt-5 max-w-2xl text-5xl md:text-6xl">Станьте<br>партнёром LIVSI</h1>
        <p class="mt-6 max-w-md text-sm text-muted leading-relaxed">
            Закупайте продукцию LIVSI по оптовым ценам и продавайте её своим клиентам
            в магазине, салоне, студии или онлайн.
        </p>
        {{-- Форма заявки — следующий инкремент. Переключатель ОПТ/РОЗНИЦА из прототипа
             не делаем: прайс высылает менеджер (решение от 31.08.2026). --}}
        <p class="mt-10 text-xs text-muted">Форма заявки подключается в следующем инкременте.</p>
    </section>
@endsection