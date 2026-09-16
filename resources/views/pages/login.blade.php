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
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.6" aria-hidden="true">
                            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path>
                            <path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path>
                        </svg>
                        Войти через Яндекс
                    </button>
                </form>
            @else
                <p class="mt-7 border-l-4 border-ink bg-shell px-5 py-4 text-sm">
                    Вход временно недоступен. Оформить заказ можно без него.
                </p>
            @endif

            {{-- Основным способом заказчик назвал код из СМС (ответ, п. 1.1),
                 VK — вторым. Пишем о них словами: выключенная кнопка выглядит
                 как рабочая, по ней жмут и идут в поддержку. --}}
            <div class="auth-divider">Скоро</div>

            <p class="auth-soon">
                Вход по коду из СМС и через ВКонтакте
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
