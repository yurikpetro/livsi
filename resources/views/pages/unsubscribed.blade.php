@extends('layouts.app')

@section('title', 'Вы отписались — LIVSI')

@section('content')
    <section class="site-container py-16 md:py-24">
        <div class="eyebrow">Рассылка</div>
        <h1 class="mt-4 text-3xl md:text-4xl">Вы отписались</h1>
        <p class="mt-4 max-w-xl text-sm leading-relaxed text-muted">
            Больше не будем присылать новости и предложения. Письма о ваших заказах — чеки, статусы доставки — по-прежнему будут приходить.
        </p>
        <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-8">В каталог <span aria-hidden="true">→</span></a>
    </section>
@endsection
