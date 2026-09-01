<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'LIVSI — уход, который чувствует тебя')</title>
    <meta name="description" content="@yield('description', 'Косметика для дома и профессионалов. Средства для понятного ежедневного ухода и точных профессиональных протоколов.')">
    <link rel="icon" href="{{ asset('img/logo.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    @include('partials.header')

    <main>
        @yield('content')
    </main>

    @include('partials.footer')
</body>
</html>