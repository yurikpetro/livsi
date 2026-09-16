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

function boot() {
    initReveal();
    initScrollEffects();
    initHeroParallax();
    initFaq();
    initCookieBar();
    initFavorites();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
