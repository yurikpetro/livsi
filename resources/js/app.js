/**
 * Эффекты витрины, перенесённые из прототипа.
 *
 * Всё здесь — надстройка над работающей страницей: без JavaScript контент
 * виден и кликается, просто без движения. Поэтому начальные состояния
 * «спрятано» задаются не в CSS, а отсюда — иначе при отключённом скрипте
 * половина главной осталась бы невидимой.
 */

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/**
 * Появление секций при прокрутке.
 *
 * Наблюдатель одноразовый: в прототипе элемент после появления снимается
 * с наблюдения, иначе блок мигал бы при каждой прокрутке мимо.
 */
function initReveal() {
    const targets = document.querySelectorAll('[data-reveal]');

    if (!targets.length) return;

    if (reduceMotion.matches || !('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('is-visible'));
        return;
    }

    targets.forEach((el) => el.classList.add('reveal-armed'));

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        },
        // Пороги из прототипа: 12 % площади и запас 8 % снизу, чтобы блок
        // начинал появляться до того, как упрётся в нижний край экрана.
        { threshold: 0.12, rootMargin: '0px 0px -8%' },
    );

    targets.forEach((el) => observer.observe(el));
}

/**
 * Полоса прогресса чтения вверху страницы и медленный сдвиг фотографии
 * первого экрана при прокрутке.
 *
 * Оба эффекта считаются в одном обработчике: слушатель прокрутки должен
 * быть один, а работа — внутри requestAnimationFrame, иначе на каждый
 * кадр прокрутки уходит по несколько пересчётов раскладки.
 */
function initScrollEffects() {
    const progress = document.querySelector('[data-scroll-progress]');
    const hero = document.querySelector('[data-hero]');

    if (!progress && !hero) return;

    let frame = 0;

    const update = () => {
        cancelAnimationFrame(frame);

        frame = requestAnimationFrame(() => {
            const scrollable = document.documentElement.scrollHeight - window.innerHeight;

            if (progress) {
                const percent = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 0;
                progress.style.setProperty('--progress', `${percent}%`);
            }

            // Сдвиг ограничен 70 пикселями: дальше фотография уехала бы
            // из-под своего блока, а вылет по краям всего 35 пикселей.
            if (hero && !reduceMotion.matches) {
                hero.style.setProperty('--scroll-y', `${Math.min(window.scrollY * 0.12, 70)}px`);
            }
        });
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
}

/**
 * Фотография первого экрана следует за курсором.
 *
 * Коэффициенты из прототипа: 13 пикселей по горизонтали и 9 по вертикали
 * от центра блока. Смещение уходит в CSS-переменные, а саму плавность
 * делает transition — так браузер не пересчитывает раскладку на каждое
 * движение мыши.
 */
function initHeroParallax() {
    const hero = document.querySelector('[data-hero]');

    if (!hero || reduceMotion.matches) return;

    // Только там, где курсор действительно есть: на тач-экране это событие
    // приходит один раз по касанию и картинка застывает сдвинутой.
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    const reset = () => {
        hero.style.setProperty('--mouse-x', '0px');
        hero.style.setProperty('--mouse-y', '0px');
    };

    hero.addEventListener('mousemove', (event) => {
        const box = hero.getBoundingClientRect();
        const x = ((event.clientX - box.left) / box.width - 0.5) * 2;
        const y = ((event.clientY - box.top) / box.height - 0.5) * 2;

        hero.style.setProperty('--mouse-x', `${x * 13}px`);
        hero.style.setProperty('--mouse-y', `${y * 9}px`);
    });

    hero.addEventListener('mouseleave', reset);
    reset();
}

/**
 * Плавное раскрытие вопросов и ответов.
 *
 * Элемент остаётся <details>: без JavaScript он открывается мгновенно,
 * но открывается — и содержимое видно поисковику и поиску по странице.
 * Скрипт только перехватывает щелчок и добавляет плавность.
 *
 * Анимируется высота самого <details>, а не внутреннего блока: содержимое
 * закрытого <details> для раскладки не существует, и его высота в момент
 * открытия ещё равна нулю — анимация шла бы от нуля к нулю.
 *
 * Высота считается по содержимому, а не фиксированным max-height, как
 * в прототипе: там стоит 150 пикселей, и ответ длиннее просто обрезается.
 */
function initFaq() {
    const items = document.querySelectorAll('[data-faq] details');

    if (!items.length || reduceMotion.matches) return;

    const easing = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

    /** Высота закрытого состояния: заголовок плюс собственные отступы. */
    const collapsedHeight = (details, summary) => {
        const style = getComputedStyle(details);

        return summary.offsetHeight
            + parseFloat(style.paddingTop)
            + parseFloat(style.paddingBottom);
    };

    items.forEach((details) => {
        const summary = details.querySelector('summary');

        if (!summary || typeof details.animate !== 'function') return;

        let animation = null;

        const finish = () => {
            details.style.overflow = '';
            animation = null;
        };

        summary.addEventListener('click', (event) => {
            event.preventDefault();

            // Незавершённую анимацию обрываем: иначе быстрые щелчки
            // оставляют блок с половинной высотой.
            if (animation) {
                animation.cancel();
                animation = null;
            }

            const from = `${details.offsetHeight}px`;

            details.style.overflow = 'hidden';

            if (!details.open) {
                details.open = true;

                animation = details.animate(
                    { height: [from, `${details.offsetHeight}px`] },
                    { duration: 350, easing },
                );

                animation.onfinish = finish;
            } else {
                animation = details.animate(
                    { height: [from, `${collapsedHeight(details, summary)}px`] },
                    { duration: 300, easing },
                );

                // Закрываем только после анимации, иначе содержимое
                // исчезнет мгновенно и анимировать будет нечего.
                animation.onfinish = () => {
                    details.open = false;
                    finish();
                };
            }
        });
    });
}

/**
 * Баннер про файлы cookie.
 *
 * Решение хранится в куке на год и на сервер не отправляется: серверу оно
 * пока не нужно, а лишний запрос ради баннера — плохой обмен. Баннер
 * показывается только после проверки, иначе он моргал бы при каждой
 * загрузке у тех, кто уже выбрал.
 */
/**
 * Избранное.
 *
 * Форма работает и без этого: обычный POST с возвратом назад. Скрипт лишь
 * убирает перезагрузку — от нажатия на сердечко страница прыгать не должна,
 * особенно в середине длинного каталога.
 */
function initFavorites() {
    const counter = document.querySelector('[data-favorites-count]');

    document.querySelectorAll('form[data-favorite]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = form.querySelector('button');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': form.querySelector('[name=_token]').value,
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) throw new Error(response.status);

                const data = await response.json();

                button.classList.toggle('liked', data.liked);
                button.setAttribute('aria-pressed', String(data.liked));
                button.setAttribute(
                    'aria-label',
                    data.liked ? 'Убрать из избранного' : 'Добавить в избранное',
                );

                if (counter) {
                    counter.textContent = data.count;
                    counter.classList.toggle('hidden', data.count === 0);
                }
            } catch {
                // Сеть отвалилась — отправляем формой, как без скрипта.
                form.submit();
            }
        });
    });
}

function initCookieBar() {
    const bar = document.querySelector('[data-cookie-bar]');

    if (!bar) return;

    const NAME = 'livsi_cookie_consent';

    const stored = document.cookie
        .split('; ')
        .find((row) => row.startsWith(NAME + '='))
        ?.split('=')[1];

    if (stored === 'all' || stored === 'necessary') return;

    bar.hidden = false;

    bar.querySelectorAll('[data-cookie-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const choice = button.dataset.cookieChoice;
            const year = 60 * 60 * 24 * 365;

            document.cookie = `${NAME}=${choice}; path=/; max-age=${year}; SameSite=Lax`;
            bar.hidden = true;
        });
    });
}

const CITY_STORAGE = 'livsi.delivery.city.v1';
const CITY_PROMPTED = 'livsi.city.prompted.v1';
let yandexMapsPromise;

function cityFromSuggestion(item) {
    const parts = String(item.displayName || '').split(',').map((part) => part.trim());
    const first = parts[0] || '';

    if (!first || /улица|проспект|переулок|проезд|шоссе|бульвар|площадь|микрорайон|округ|район|область|край|республика|автономный округ/i.test(first)
        || parts.slice(1).some((part) => /^\d+[а-яa-z]?$/i.test(part))
        || parts.some((part) => /муниципий|уезд|воеводство/i.test(part))) return null;

    const city = first.replace(/^(?:город|посёлок(?: городского типа)?|село|деревня|станица|хутор)\s+/i, '').trim();
    const region = parts.find((part) => /край|область|республика|автономный округ|автономная область/i.test(part))
        || ({ Ставрополь: 'Ставропольский край', Москва: 'Москва', 'Санкт-Петербург': 'Санкт-Петербург', Краснодар: 'Краснодарский край', 'Ростов-на-Дону': 'Ростовская область', Казань: 'Республика Татарстан' })[city]
        || '';

    return city ? { city, region } : null;
}

function loadYandexMaps(mapsKey, suggestKey) {
    if (!mapsKey) return Promise.resolve(null);
    if (window.ymaps) return new Promise((resolve) => window.ymaps.ready(() => resolve(window.ymaps)));
    if (yandexMapsPromise) return yandexMapsPromise;

    yandexMapsPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const url = new URL('https://api-maps.yandex.ru/2.1/');
        url.searchParams.set('lang', 'ru_RU');
        url.searchParams.set('apikey', mapsKey);
        if (suggestKey) url.searchParams.set('suggest_apikey', suggestKey);
        script.src = url.toString();
        script.onload = () => window.ymaps
            ? window.ymaps.ready(() => resolve(window.ymaps))
            : reject(new Error('API карт недоступен'));
        script.onerror = () => reject(new Error('API карт недоступен'));
        document.head.append(script);
    }).catch((error) => {
        yandexMapsPromise = null;
        throw error;
    });

    return yandexMapsPromise;
}

function initCitySelector() {
    const root = document.querySelector('[data-city-selector]');
    const trigger = document.querySelector('[data-city-open]');
    if (!root || !trigger) return;

    const label = trigger.querySelector('[data-city-label]');
    const confirm = root.querySelector('[data-city-confirm-screen]');
    const choose = root.querySelector('[data-city-choose-screen]');
    const search = root.querySelector('[data-city-search]');
    const results = root.querySelector('[data-city-results]');
    const popular = root.querySelector('[data-city-popular]');
    const saveTyped = root.querySelector('[data-city-save-typed]');
    const error = root.querySelector('[data-city-error]');
    let candidate = null;
    let suggestionTimer;
    let suggestionRequest = 0;
    let lastFocus = null;

    const readSaved = () => {
        try {
            const saved = JSON.parse(localStorage.getItem(CITY_STORAGE) || 'null');
            return saved && typeof saved.city === 'string' && saved.city.trim()
                ? { city: saved.city.trim(), region: typeof saved.region === 'string' ? saved.region : '' }
                : null;
        } catch { return null; }
    };
    const markPrompted = () => {
        try { localStorage.setItem(CITY_PROMPTED, '1'); } catch { /* Хранилище может быть отключено. */ }
    };
    const close = () => {
        root.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('city-dialog-open');
        markPrompted();
        lastFocus?.focus();
    };
    const open = (screen) => {
        if (root.hidden) lastFocus = document.activeElement;
        root.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        document.body.classList.add('city-dialog-open');
        confirm.hidden = screen !== 'confirm';
        choose.hidden = screen !== 'choose';
        root.querySelector('.city-dialog').setAttribute('aria-labelledby', screen === 'confirm' ? 'city-confirm-title' : 'city-choose-title');
        if (screen === 'choose') search.focus();
        else root.querySelector('[data-city-yes]').focus();
    };
    const selectCity = (city, region = '') => {
        const selected = { city: city.trim().slice(0, 120), region: region.trim().slice(0, 120) };
        if (!selected.city) return;
        label.textContent = selected.city;
        try { localStorage.setItem(CITY_STORAGE, JSON.stringify(selected)); } catch { /* Работает до перезагрузки. */ }
        window.dispatchEvent(new CustomEvent('livsi:city-selected', { detail: selected }));
        close();
    };
    const setError = (message) => {
        error.textContent = message;
        error.hidden = !message;
    };
    const clearResults = () => {
        results.replaceChildren();
        results.hidden = true;
        search.setAttribute('aria-expanded', 'false');
    };
    trigger.addEventListener('click', () => { clearResults(); setError(''); open('choose'); });
    root.querySelectorAll('[data-city-close]').forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !root.hidden) close();
    });
    root.querySelector('[data-city-yes]').addEventListener('click', () => {
        if (candidate) selectCity(candidate.city, candidate.region);
    });
    root.querySelector('[data-city-no]').addEventListener('click', () => open('choose'));
    popular.querySelectorAll('[data-popular-city]').forEach((button) => button.addEventListener('click', () =>
        selectCity(button.dataset.popularCity, button.dataset.popularRegion)));
    saveTyped.addEventListener('click', () => selectCity(search.value));
    window.addEventListener('livsi:city-selected', (event) => {
        label.textContent = event.detail.city || 'Выберите город';
        if (event.detail.city) {
            try { localStorage.setItem(CITY_STORAGE, JSON.stringify(event.detail)); } catch { /* Только текущая вкладка. */ }
            markPrompted();
        } else {
            try { localStorage.removeItem(CITY_STORAGE); } catch { /* Хранилище может быть отключено. */ }
        }
    });

    search.addEventListener('input', () => {
        clearTimeout(suggestionTimer);
        clearResults();
        setError('');
        const query = search.value.trim();
        const request = ++suggestionRequest;
        popular.hidden = query.length >= 2;
        saveTyped.hidden = true;
        if (query.length < 2) return;
        suggestionTimer = setTimeout(async () => {
            try {
                const ymaps = await loadYandexMaps(root.dataset.mapsKey, root.dataset.suggestKey);
                if (!ymaps || !root.dataset.suggestKey) throw new Error('Подсказки недоступны');
                const suggestions = (await ymaps.suggest(query, { results: 12 }))
                    .map((item) => ({ item, place: cityFromSuggestion(item) }))
                    .filter(({ place }) => place)
                    .slice(0, 8);
                if (request !== suggestionRequest || root.hidden) return;
                for (const { item, place } of suggestions) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.setAttribute('role', 'option');
                    button.textContent = item.displayName;
                    button.addEventListener('click', () => selectCity(place.city, place.region));
                    results.append(button);
                }
                results.hidden = !suggestions.length;
                search.setAttribute('aria-expanded', suggestions.length ? 'true' : 'false');
                if (!suggestions.length) {
                    saveTyped.hidden = false;
                    setError('Совпадений нет. Можно выбрать введённый город вручную.');
                }
            } catch {
                if (request === suggestionRequest) {
                    saveTyped.hidden = false;
                    setError('Подсказки сейчас недоступны. Можно выбрать введённый город вручную.');
                }
            }
        }, 250);
    });
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !results.hidden && results.firstElementChild) {
            event.preventDefault();
            results.firstElementChild.click();
        }
    });

    const saved = readSaved();
    if (saved) { label.textContent = saved.city; return; }
    try { if (localStorage.getItem(CITY_PROMPTED)) return; } catch { /* Первый визит. */ }

    loadYandexMaps(root.dataset.mapsKey, root.dataset.suggestKey)
        .then(async (ymaps) => {
            if (!ymaps) throw new Error('API карт недоступен');
            const location = await ymaps.geolocation.get({ provider: 'yandex', autoReverseGeocode: false, timeout: 8000 });
            const coordinates = location?.geoObjects.get(0)?.geometry.getCoordinates();
            if (!Array.isArray(coordinates) || coordinates.length !== 2) throw new Error('Координаты недоступны');

            const response = await fetch(root.dataset.geocodeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ latitude: coordinates[0], longitude: coordinates[1] }),
            });
            if (!response.ok) throw new Error('Город не определён');

            return response.json();
        })
        .then((place) => {
            if (readSaved() || root.hidden === false) return;
            try { if (localStorage.getItem(CITY_PROMPTED)) return; } catch { /* Продолжаем. */ }
            if (place?.city) {
                candidate = { city: place.city, region: place.region || '' };
                root.querySelector('[data-city-candidate]').textContent = [candidate.city, candidate.region].filter(Boolean).join(', ');
                open('confirm');
            } else {
                open('choose');
            }
        })
        .catch(() => { if (!readSaved() && root.hidden) open('choose'); });
}

function initDeliveryPoints() {
    const root = document.querySelector('[data-delivery-points]');

    if (!root) return;

    const methods = root.dataset.methods.split(',').filter(Boolean);
    const labels = { cdek_point: 'СДЭК', yandex_point: 'Яндекс', ozon_point: 'Ozon' };
    const colors = { cdek_point: '#1f9d55', yandex_point: '#e43b2f', ozon_point: '#2464ce' };
    const radios = [...document.querySelectorAll('[name="delivery_method"]')];
    const deliveryMethods = document.querySelector('[data-delivery-methods]');
    const city = document.querySelector('[name="delivery_city"]');
    const region = document.querySelector('[name="delivery_region"]');
    const hint = document.querySelector('[name="delivery_point_hint"]');
    const addressField = document.querySelector('[data-delivery-address]');
    const ozonNotice = document.querySelector('[data-ozon-notice]');
    const search = root.querySelector('[data-points-search]');
    const status = root.querySelector('[data-points-status]');
    const pointId = root.querySelector('[data-point-id]');
    const selected = root.querySelector('[data-point-selected]');
    const pointMethod = root.querySelector('[data-point-method]');
    const dialog = root.querySelector('[data-points-dialog]');
    const location = root.querySelector('[data-points-location]');
    const filter = root.querySelector('[data-points-filter]');
    const count = root.querySelector('[data-points-count]');
    const list = root.querySelector('[data-points-list]');
    const more = root.querySelector('[data-points-more]');
    const mapElement = root.querySelector('[data-points-map]');
    const mapFallback = root.querySelector('[data-points-map-fallback]');
    const instruction = root.querySelector('[data-points-instruction]');
    let map = null;
    let clusterer = null;
    let controller = null;
    let selectedAddress = null;
    let points = [];
    let cityCenter = null;
    let visibleCount = 0;
    let loading = false;
    const currentMethod = () => radios.find((radio) => radio.checked)?.value;

    const loadMaps = () => loadYandexMaps(root.dataset.mapsKey, root.dataset.suggestKey);

    const carrierForPoint = (point) => ({ cdek: 'cdek_point', yataxi: 'yandex_point', ozonlog: 'ozon_point' })[point.provider];
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);

    const choosePoint = (point, method) => {
        pointId.value = point.id;
        pointMethod.value = method;
        selectedAddress = point.address.slice(0, 255);
        hint.value = selectedAddress;
        selected.textContent = `Выбран ПВЗ: ${labels[method]} · ${point.address}${point.timetable ? ` · ${point.timetable}` : ''}`;
        selected.hidden = false;
        ozonNotice.hidden = method !== 'ozon_point';
        dialog.close();
        search.focus();
    };

    const reset = () => {
        const hasCity = city.value.trim().length >= 2;
        const canUseCourier = hasCity && city.value.trim().toLocaleLowerCase('ru')
            === root.dataset.courierCity.trim().toLocaleLowerCase('ru');
        deliveryMethods.hidden = !hasCity;
        radios.forEach((radio) => {
            radio.disabled = !hasCity || (radio.value === 'stavropol_courier' && !canUseCourier);
            if (radio.disabled) radio.checked = false;
        });
        controller?.abort();
        controller = null;
        loading = false;
        if (selectedAddress && hint.value === selectedAddress) hint.value = '';
        selectedAddress = null;
        pointId.value = '';
        pointMethod.value = '';
        points = [];
        cityCenter = null;
        visibleCount = 0;
        list.replaceChildren();
        filter.value = '';
        more.hidden = true;
        status.hidden = true;
        selected.hidden = true;
        mapElement.hidden = true;
        mapFallback.hidden = true;
        clusterer?.removeAll();
        if (dialog.open) dialog.close();
        const isPointMethod = currentMethod() === 'pickup_point';
        const isCourier = currentMethod() === 'stavropol_courier';
        addressField.hidden = !isCourier;
        addressField.querySelector('input').required = isCourier;
        ozonNotice.hidden = true;
        root.hidden = !isPointMethod;
        instruction.textContent = methods.length
            ? 'Покажем ПВЗ всего региона. Найдите удобный пункт на карте или в списке.'
            : 'Службы доставки пока подключаются. Пожелания по доставке можно оставить в комментарии к заказу.';
        search.hidden = !isPointMethod || !methods.length || city.value.trim().length < 2;
        search.textContent = root.dataset.mapsKey ? 'Выбрать пункт выдачи на карте' : 'Выбрать пункт выдачи';
    };

    const hasCoordinates = (point) => point.lat != null && point.lng != null
        && Number.isFinite(Number(point.lat)) && Number.isFinite(Number(point.lng));

    const distanceFromCity = (point) => {
        if (!cityCenter || !hasCoordinates(point)) return Infinity;
        const latitude = (Number(point.lat) - cityCenter[0]) * Math.PI / 180;
        const longitude = (Number(point.lng) - cityCenter[1]) * Math.PI / 180;
        const a = Math.sin(latitude / 2) ** 2
            + Math.cos(cityCenter[0] * Math.PI / 180) * Math.cos(Number(point.lat) * Math.PI / 180) * Math.sin(longitude / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };

    const matchingPoints = () => {
        const query = filter.value.trim().toLocaleLowerCase('ru');
        return query
            ? points.filter((point) => `${labels[carrierForPoint(point)]} ${point.city} ${point.address}`.toLocaleLowerCase('ru').includes(query))
                .sort((a, b) => Number(b.city.toLocaleLowerCase('ru') === query) - Number(a.city.toLocaleLowerCase('ru') === query))
            : points;
    };

    const renderList = () => {
        const matches = matchingPoints();
        visibleCount = Math.min(matches.length, visibleCount || 80);
        count.textContent = loading && !points.length
            ? 'Загружаем пункты выдачи…'
            : `${matches.length} ПВЗ в списке${points.length > matches.length ? ` из ${points.length}` : ''}`;
        list.replaceChildren();

        if (!matches.length) {
            const empty = document.createElement('p');
            empty.className = 'pickup-count';
            empty.textContent = loading ? 'Подождите, загружаем адреса региона.'
                : 'По этому запросу пунктов нет. Измените поиск.';
            list.append(empty);
        }

        for (const point of matches.slice(0, visibleCount)) {
            const method = carrierForPoint(point);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `pickup-item${pointId.value === point.id && pointMethod.value === method ? ' is-selected' : ''}`;
            const provider = document.createElement('span');
            provider.className = 'pickup-item-provider';
            provider.textContent = labels[method];
            provider.style.color = colors[method];
            const address = document.createElement('span');
            address.className = 'pickup-item-address';
            address.textContent = point.address;
            button.append(provider, address);
            if (point.timetable) {
                const hours = document.createElement('span');
                hours.className = 'pickup-item-hours';
                hours.textContent = point.timetable;
                button.append(hours);
            }
            button.addEventListener('click', () => choosePoint(point, method));
            list.append(button);
        }

        more.hidden = visibleCount >= matches.length;
    };

    const resolveCityCenter = async (cityName, regionName) => {
        try {
            const ymaps = await loadMaps();
            if (ymaps) {
                const result = await ymaps.geocode([cityName, regionName, 'Россия'].filter(Boolean).join(', '), {
                    results: 1,
                });
                const coordinates = result.geoObjects.get(0)?.geometry.getCoordinates();
                if (Array.isArray(coordinates) && coordinates.every(Number.isFinite)) return coordinates;
            }
        } catch { /* Серверный Геокодер остаётся доступен, если JS API не ответил. */ }

        if (!regionName) return null;
        try {
            const response = await fetch(root.dataset.geocodeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ city: cityName, region: regionName }),
            });
            if (!response.ok) return null;
            const coordinates = await response.json();
            return Number.isFinite(coordinates.latitude) && Number.isFinite(coordinates.longitude)
                ? [coordinates.latitude, coordinates.longitude] : null;
        } catch { return null; }
    };

    const drawMap = async () => {
        if (!dialog.open) return;
        const ymaps = await loadMaps();
        if (!ymaps || !points.some(hasCoordinates)) {
            mapFallback.hidden = false;
            mapElement.hidden = true;
            return;
        }
        mapFallback.hidden = true;
        mapElement.hidden = false;
        const cityPoint = points.find((point) => hasCoordinates(point)
            && point.city.trim().toLocaleLowerCase('ru') === city.value.trim().toLocaleLowerCase('ru'));
        const fallbackPoint = cityPoint || points.find(hasCoordinates);
        const center = cityCenter || [Number(fallbackPoint.lat), Number(fallbackPoint.lng)];
        const zoom = cityCenter || cityPoint ? 12 : 9;
        if (!map) {
            map = new ymaps.Map(mapElement, { center, zoom, controls: ['zoomControl'] });
            clusterer = new ymaps.Clusterer({ preset: 'islands#invertedVioletClusterIcons' });
            map.geoObjects.add(clusterer);
        }
        map.container.fitToViewport();
        clusterer.removeAll();
        clusterer.add(points.filter(hasCoordinates).map((point) => {
            const method = carrierForPoint(point);
            const mark = new ymaps.Placemark([Number(point.lat), Number(point.lng)], {
                hintContent: `${labels[method]} · ${escapeHtml(point.address)}`,
            }, { preset: 'islands#circleDotIcon', iconColor: colors[method] });
            mark.events.add('click', () => choosePoint(point, method));
            return mark;
        }));
        map.setCenter(center, zoom);
    };

    const load = async () => {
        if (currentMethod() !== 'pickup_point' || city.value.trim().length < 2 || !methods.length) return;
        controller?.abort();
        const requestController = new AbortController();
        controller = requestController;
        loading = true;
        status.hidden = false;
        status.textContent = 'Загружаем пункты выдачи региона…';
        const requestedCity = city.value.trim();
        const requestedRegion = region.value.trim();
        const centerPromise = resolveCityCenter(requestedCity, requestedRegion);
        const found = [];
        let failed = 0;

        try {
            await Promise.all(methods.map(async (method) => {
                let offset = 0;
                try {
                    do {
                        const url = new URL(root.dataset.url, window.location.href);
                        url.searchParams.set('method', method);
                        url.searchParams.set('city', requestedCity);
                        if (requestedRegion) url.searchParams.set('region', requestedRegion);
                        url.searchParams.set('offset', String(offset));
                        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: requestController.signal });
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'Пункты выдачи недоступны.');
                        found.push(...data.points);
                        offset += 1000;
                        if (!data.has_more || offset >= 100000) break;
                    } while (!requestController.signal.aborted);
                } catch (error) {
                    if (error.name !== 'AbortError') failed += 1;
                }
            }));

            if (requestController.signal.aborted) return;
            cityCenter = await centerPromise;
            if (requestController.signal.aborted) return;
            points = found.filter((point) => carrierForPoint(point));
            points.sort((a, b) => distanceFromCity(a) - distanceFromCity(b)
                || (labels[carrierForPoint(a)] + a.address).localeCompare(labels[carrierForPoint(b)] + b.address, 'ru'));
            visibleCount = 80;
            location.textContent = requestedRegion
                ? `${requestedCity} · ПВЗ ${requestedRegion}`
                : `${requestedCity} · Выберите населённый пункт из подсказок, чтобы показать весь регион`;
            renderList();
            status.textContent = points.length
                ? `${points.length} ПВЗ ${requestedRegion ? `по региону «${requestedRegion}»` : `в населённом пункте «${requestedCity}»`}. ${root.dataset.freeShipping === '1' ? 'Доставка бесплатная.' : 'Стоимость доставки менеджер подтвердит отдельно.'}${failed ? ' Часть служб сейчас недоступна.' : ''}`
                : 'ПВЗ не найдены или временно недоступны. Пожелания по доставке можно оставить в комментарии к заказу.';
            try { await drawMap(); } catch { mapFallback.hidden = false; }
        } catch (error) {
            if (error.name !== 'AbortError') {
                status.textContent = 'Список ПВЗ временно недоступен. Пожелания по доставке можно оставить в комментарии к заказу.';
            }
        } finally {
            if (controller === requestController) loading = false;
        }
    };

    const cityChanged = () => {
        reset();
    };

    const openPointsDialog = () => {
        if (currentMethod() !== 'pickup_point' || city.value.trim().length < 2 || !methods.length) return;
        if (!dialog.open) dialog.showModal();
        location.textContent = [city.value.trim(), region.value.trim()].filter(Boolean).join(' · ');
        if (!loading && !points.length) load();
        renderList();
        filter.focus();
        if (points.length) drawMap().catch(() => { mapFallback.hidden = false; });
    };

    radios.forEach((radio) => {
        radio.addEventListener('change', () => {
            reset();
            if (radio.value === 'pickup_point') openPointsDialog();
        });
        radio.addEventListener('click', () => {
            if (radio.value === 'pickup_point' && radio.checked) {
                queueMicrotask(() => { if (!dialog.open) openPointsDialog(); });
            }
        });
    });
    city.addEventListener('input', () => { region.value = ''; cityChanged(); });
    city.addEventListener('change', () => {
        window.dispatchEvent(new CustomEvent('livsi:city-selected', {
            detail: { city: city.value.trim(), region: region.value.trim() },
        }));
        load();
    });
    window.addEventListener('livsi:city-selected', (event) => {
        if (city.value.trim() === event.detail.city && region.value.trim() === event.detail.region) return;
        city.value = event.detail.city;
        region.value = event.detail.region;
        reset();
        if (currentMethod() === 'pickup_point') load();
    });
    search.addEventListener('click', openPointsDialog);
    root.querySelector('[data-points-close]').addEventListener('click', () => dialog.close());
    filter.addEventListener('input', () => { visibleCount = 80; renderList(); });
    filter.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
    more.addEventListener('click', () => { visibleCount += 80; renderList(); });

    let cityToolsReady = false;
    const setupCityTools = (ymaps) => {
        if (!ymaps || cityToolsReady) return;
        cityToolsReady = true;
        if (root.dataset.suggestKey) {
            const suggest = new ymaps.SuggestView(city.id, { results: 8 });
            suggest.events.add('select', (event) => {
                const place = cityFromSuggestion(event.get('item'));
                if (!place) return;
                city.value = place.city;
                region.value = place.region;
                cityChanged();
                window.dispatchEvent(new CustomEvent('livsi:city-selected', { detail: place }));
                load();
            });
        }
    };

    city.addEventListener('focus', () => loadMaps().then(setupCityTools).catch(() => {}));
    try {
        const remembered = JSON.parse(localStorage.getItem(CITY_STORAGE) || 'null');
        if (remembered?.city && !city.value.trim()) {
            city.value = remembered.city;
            region.value = remembered.region || '';
        }
    } catch { /* Хранилище браузера может быть отключено. */ }
    reset();
    if (root.dataset.mapsKey) loadMaps().then(setupCityTools).catch(() => {});
    if (currentMethod() === 'pickup_point' && city.value.trim().length >= 2) load();
}

function boot() {
    initReveal();
    initScrollEffects();
    initHeroParallax();
    initFaq();
    initCookieBar();
    initFavorites();
    initCitySelector();
    initDeliveryPoints();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
