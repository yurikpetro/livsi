{{--
    Баннер про файлы cookie.

    Технические файлы — сессия и корзина — ставятся всегда: без них сайт
    не работает, и согласия они не требуют. Яндекс Метрика (если счётчик
    выбран в «Аналитика сайта» → «Подключение») включается только после
    «Принять все» — см. partials/metrika.blade.php.

    Кнопка «Только необходимые» стоит первой — выбор по умолчанию не должен
    подталкивать к согласию на слежение. Изменить решение можно ссылкой
    «Настройки cookie» в подвале.
--}}
<div class="cookie-bar"
     data-cookie-bar
     role="region"
     aria-label="Использование файлов cookie"
     hidden>
    <p>
        Мы используем файлы cookie, необходимые для работы сайта: они хранят сессию
        и содержимое корзины. {{ \App\Analytics\AnalyticsSettings::counterId() ? 'Аналитические файлы (Яндекс Метрика)' : 'Аналитические файлы' }} подключаются только с вашего согласия.
        Подробнее — в <a href="{{ route('legal.privacy') }}">политике конфиденциальности</a>.
    </p>

    <div class="cookie-bar-actions">
        <button type="button" class="is-secondary" data-cookie-choice="necessary">
            Только необходимые
        </button>
        <button type="button" class="is-primary" data-cookie-choice="all">
            Принять все
        </button>
    </div>
</div>
