{{--
    Яндекс Метрика — только с согласия на аналитические cookie («Принять все»
    в окне про cookie), как обещает политика конфиденциальности. Нажал
    «Принять все» — счётчик включается сразу, без перезагрузки страницы.

    Просмотр страницы отправляем сами (defer + hit), чтобы вычистить из
    адресов токен заказа: ссылка из письма открывает заказ без входа, ей
    нечего делать в статистике. Метки utm и адрес, откуда пришли, не трогаем —
    по ним Метрика определяет источник.

    Вебвизор на оформлении, заказе, в кабинете и на входе выключен: там
    имена, телефоны и адреса.
--}}
@php
    $metrikaId = \App\Analytics\AnalyticsSettings::counterId();
    $metrikaPrivate = request()->routeIs('checkout.*', 'order.*', 'account', 'account.*', 'login', 'login.*', 'unsubscribe', 'auth.*');
@endphp
@if ($metrikaId)
    <script>
        (function () {
            var ID = {{ $metrikaId }};
            var WEBVISOR = @js(\App\Analytics\AnalyticsSettings::webvisor() && ! $metrikaPrivate);
            var started = false;
            window.dataLayer = window.dataLayer || [];

            function allowed() {
                return document.cookie.split('; ').indexOf('livsi_cookie_consent=all') !== -1;
            }
            function clean(url) {
                try {
                    var u = new URL(url, location.href);
                    if (u.origin !== location.origin) return url;
                    u.searchParams.delete('token');
                    return u.toString();
                } catch (e) {
                    return url;
                }
            }
            function start() {
                if (started || !allowed()) return;
                started = true;
                (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
                m[i].l=1*new Date();
                for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
                k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
                (window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
                ym(ID, 'init', {defer: true, clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: WEBVISOR, ecommerce: 'dataLayer'});
                ym(ID, 'hit', clean(location.href), {title: document.title, referer: document.referrer ? clean(document.referrer) : ''});
            }

            start();
            window.addEventListener('livsi:consent', start);
        })();
    </script>
@endif
