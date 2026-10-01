<div class="city-overlay" data-city-selector
     data-maps-key="{{ config('livsi.delivery.maps_api_key') }}"
     data-suggest-key="{{ config('livsi.delivery.suggest_api_key') }}"
     data-geocode-url="{{ route('delivery.city.geocode') }}"
     hidden>
    <div class="city-backdrop" data-city-close></div>
    <section id="site-city-dialog" class="city-dialog" role="dialog" aria-modal="true" aria-labelledby="city-confirm-title">
        <button type="button" class="city-dialog-close" data-city-close aria-label="Закрыть">×</button>
        <p class="city-dialog-kicker">Доставка по России</p>
        <div data-city-confirm-screen hidden>
            <h2 id="city-confirm-title">Это ваш город?</h2>
            <p class="city-dialog-candidate" data-city-candidate></p>
            <p class="city-dialog-copy">Город определён приблизительно по IP-адресу. Вы всегда сможете изменить его здесь.</p>
            <div class="city-dialog-actions">
                <button type="button" class="btn btn-dark" data-city-yes>Да, верно</button>
                <button type="button" class="btn btn-outline" data-city-no>Нет, выбрать другой</button>
            </div>
        </div>
        <div data-city-choose-screen hidden>
            <h2 id="city-choose-title">Выберите город</h2>
            <label class="sr-only" for="site-city-search">Город или населённый пункт</label>
            <input id="site-city-search" class="city-dialog-input" data-city-search type="search"
                   placeholder="Начните вводить название" autocomplete="off" aria-controls="site-city-results" aria-expanded="false">
            <div id="site-city-results" class="city-dialog-results" data-city-results role="listbox" hidden></div>
            <p class="city-dialog-error" data-city-error role="status" hidden></p>
            <button type="button" class="city-dialog-typed" data-city-save-typed hidden>Выбрать введённый город</button>
            <div class="city-dialog-popular" data-city-popular>
                @foreach ([
                    ['Ставрополь', 'Ставропольский край'],
                    ['Москва', 'Москва'],
                    ['Санкт-Петербург', 'Санкт-Петербург'],
                    ['Краснодар', 'Краснодарский край'],
                    ['Ростов-на-Дону', 'Ростовская область'],
                    ['Казань', 'Республика Татарстан'],
                ] as [$city, $region])
                    <button type="button" data-popular-city="{{ $city }}" data-popular-region="{{ $region }}">{{ $city }}</button>
                @endforeach
            </div>
            <p class="city-dialog-copy">Город влияет на список пунктов выдачи при оформлении заказа. <a href="{{ route('legal.privacy') }}">Обработка данных</a></p>
        </div>
    </section>
</div>
