@extends('layouts.app')

@section('title', 'Вход — LIVSI')

@section('content')
    <section class="auth-screen">
        <div class="auth-card">
            <div class="eyebrow">Личный кабинет</div>
            <h1 class="auth-title">Вход</h1>

            <p class="auth-lede">
                Чтобы видеть свои заказы и повторять их одним нажатием.
            </p>

            @if ($yandexReady)
                <form method="POST" action="{{ route('auth.yandex') }}" class="mt-7">
                    @csrf
                    <button type="submit" class="btn btn-dark auth-method">
                        <img src="{{ asset('img/auth-yandex.svg') }}?v=2" class="auth-method-icon" width="28" height="28" alt="" aria-hidden="true">
                        Войти через Яндекс
                    </button>
                </form>
            @endif

            @if ($vkReady)
                <div class="mt-3 grid gap-3">
                    @foreach (['vk' => 'ВКонтакте', 'mail' => 'Mail.ru'] as $provider => $label)
                        <form method="POST" action="{{ route('auth.' . $provider) }}">
                            @csrf
                            <button type="submit" class="btn btn-dark auth-method">
                                <img src="{{ asset('img/auth-' . $provider . '.svg') }}?v=2" class="auth-method-icon" width="28" height="28" alt="" aria-hidden="true">
                                Войти через {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif

            @if (! $yandexReady && ! $vkReady)
                <p class="mt-7 border-l-4 border-ink bg-shell px-5 py-4 text-sm">
                    Вход временно недоступен. Оформить заказ можно без него.
                </p>
            @endif

            <div class="auth-divider">Скоро</div>

            <p class="auth-soon">
                Вход по коду из СМС
            </p>

            <p class="auth-note">
                Для покупки вход не нужен — заказ можно оформить гостем. Такой заказ
                не пропадёт: он открывается по своей ссылке, а привязать его к аккаунту
                можно прямо со страницы заказа.
            </p>

            <p class="auth-fineprint">
                Продолжая, вы соглашаетесь с
                <a href="{{ route('legal.privacy') }}" class="underline hover:text-ink">политикой обработки персональных данных</a>.
            </p>
        </div>
    </section>
@endsection
