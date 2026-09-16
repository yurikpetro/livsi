<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'LIVSI — уход, который чувствует тебя')</title>
    <meta name="description" content="@yield('description', 'Косметика для дома и профессионалов. Средства для понятного ежедневного ухода и точных профессиональных протоколов.')">

    {{-- Канонический адрес задаётся страницей: у каталога он без параметра
         сортировки, иначе порядок выдачи плодил бы дубли. --}}
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    @isset($robots)
        <meta name="robots" content="{{ $robots }}">
    @endisset

    <meta property="og:site_name" content="LIVSI">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', 'LIVSI — уход, который чувствует тебя')">
    <meta property="og:description" content="@yield('description', 'Косметика для дома и профессионалов.')">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:image" content="@yield('og_image', asset('img/catalog/fresh-hero.jpg'))">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="{{ asset('img/logo.svg') }}" type="image/svg+xml">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
{{-- Колонка во всю высоту экрана: без неё на коротких страницах — пустое
     избранное, пустая корзина, короткий документ — подвал вставал посреди
     экрана, а под ним оставалась белая полоса. --}}
<body class="flex min-h-screen flex-col">
    {{-- Полоса прогресса чтения — как в прототипе. Ширину задаёт скрипт. --}}
    <div class="scroll-progress" data-scroll-progress aria-hidden="true"></div>

    @include('partials.header')

    <main class="flex-1">
        {{-- Короткие сообщения после перенаправления: «вы вышли», «корзина пуста».
             До этого их флешили, но нигде не выводили. --}}
        @if (session('status'))
            <div class="site-container pt-8" role="status">
                <p class="border-l-4 border-ink bg-shell px-5 py-4 text-sm">{{ session('status') }}</p>
            </div>
        @endif

        @yield('content')
    </main>

    @include('partials.footer')

    {{-- Один экземпляр на страницу: карточки в листинге просто присылают
         ему событие quick-view с идентификатором товара. --}}
    @livewire('quick-view')

    @include('partials.cookie-bar')
</body>
</html>
