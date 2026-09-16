@extends('layouts.app')

@section('title', 'Избранное — LIVSI')

@section('content')
    <section class="site-container py-16 md:py-24">
        <div class="eyebrow">Избранное</div>
        <h1 class="mt-4 text-3xl md:text-4xl">Отложено на потом</h1>

        @if ($products->isEmpty())
            <p class="mt-8 max-w-xl border-l-4 border-line bg-shell px-5 py-4 text-sm leading-relaxed">
                Пока пусто. Сердечко на карточке товара откладывает его сюда —
                вход для этого не нужен.
                <a href="{{ route('catalog.index') }}" class="underline hover:text-green">Перейти в каталог</a>.
            </p>
        @else
            <p class="mt-4 text-sm text-muted">
                {{ \App\Support\Plural::products($products->count()) }}
            </p>

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            {{-- Список гостя живёт в cookie на 90 дней. Сказать об этом честнее,
                 чем промолчать и потерять его вместе с очисткой браузера. --}}
            @guest
                <p class="mt-10 max-w-xl text-xs leading-relaxed text-muted">
                    Список хранится в этом браузере. Чтобы он не потерялся и открывался
                    с телефона, <a href="{{ route('login') }}" class="underline hover:text-ink">войдите</a> —
                    отложенное перенесётся в аккаунт.
                </p>
            @endguest
        @endif
    </section>
@endsection
