<?php

/*
|--------------------------------------------------------------------------
| Настройки проекта LIVSI
|--------------------------------------------------------------------------
|
| Здесь только то, что относится к развёртыванию. Всё, что заказчик должен
| менять сам — пороги бесплатной доставки и подарка, тексты, часы работы —
| живёт в таблице `settings` и правится через админку.
|
*/

$isDev = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [

    /*
    | Администратор Back Office. Создаётся сидером AdminUserSeeder.
    |
    | Пароль по умолчанию подставляется только на локальной машине.
    | На боевом контуре ADMIN_EMAIL и ADMIN_PASSWORD задаются в окружении,
    | иначе администратор не создаётся вообще.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Администратор'),
        'email' => env('ADMIN_EMAIL', $isDev ? 'admin@livsi.shop' : null),
        'password' => env('ADMIN_PASSWORD', $isDev ? 'livsi-admin' : null),
    ],

    /*
    | Куда уходят уведомления о заявках. Основное место хранения — настройка
    | `manager_email` в базе, чтобы заказчик менял адрес сам. Значение отсюда
    | работает, пока настройка не заполнена.
    */
    'manager_email' => env('MANAGER_EMAIL'),

    /*
    | Вход через Яндекс ID.
    |
    | Redirect URI здесь не задаётся: он берётся из маршрута
    | `auth.yandex.callback`. Адрес должен совпадать с зарегистрированным
    | у провайдера посимвольно, а два источника правды рано или поздно
    | разъезжаются.
    */
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'auth_url' => env('YANDEX_AUTH_URL', 'https://oauth.yandex.ru/authorize'),
        'token_url' => env('YANDEX_TOKEN_URL', 'https://oauth.yandex.ru/token'),
        'info_url' => env('YANDEX_INFO_URL', 'https://login.yandex.ru/info'),
    ],

    /*
    | Оплата через ЮKassa.
    |
    | Ключи выдаются в личном кабинете. У тестового магазина секретный ключ
    | начинается с `test_` — по этому признаку определяется тестовый режим,
    | отдельного флага заводить не нужно, и невозможно случайно принять
    | боевые платежи, думая, что они тестовые.
    */
    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        'api_url' => env('YOOKASSA_API_URL', 'https://api.yookassa.ru/v3'),

        /*
        | Сети, из которых ЮKassa шлёт уведомления. Список опубликован
        | в документации провайдера. Вебхук без этой проверки — открытая
        | ручка, которой можно объявить любой заказ оплаченным.
        */
        'webhook_ips' => array_filter(array_map(
            'trim',
            explode(',', (string) env('YOOKASSA_WEBHOOK_IPS', implode(',', [
                '185.71.76.0/27',
                '185.71.77.0/27',
                '77.75.153.0/25',
                '77.75.156.11/32',
                '77.75.156.35/32',
                '77.75.154.128/25',
                '2a02:5180::/32',
            ]))),
        )),
    ],

    'order_reserve_minutes' => (int) env('ORDER_RESERVE_MINUTES', 1440),

    'delivery' => [
        'country_code' => env('DELIVERY_COUNTRY_CODE', 'RU'),
        'origin_city' => env('DELIVERY_ORIGIN_CITY', 'Ставрополь'),
        'maps_api_key' => env('YANDEX_MAPS_API_KEY'),
        'suggest_api_key' => env('YANDEX_SUGGEST_API_KEY'),
        'geocoder_api_key' => env('YANDEX_GEOCODER_API_KEY'),
    ],

    'apiship' => [
        'token' => env('APISHIP_TOKEN'),
        'api_url' => env('APISHIP_API_URL', 'https://api.apiship.ru/v1'),
        'source_ip' => env('APISHIP_SOURCE_IP'),
        'active_providers' => array_filter(array_map('trim', explode(',', (string) env('APISHIP_ACTIVE_PROVIDERS', 'ozonlog')))),
    ],

    /*
    | Оповещения о сбоях в Телеграм.
    |
    | Смысл простой: об ошибке на сайте узнавать не от покупателя. Бот
    | пишет в рабочую группу, разобранную на топики, — у каждого топика
    | свой номер, он же message_thread_id, и его видно в ссылке на топик.
    |
    | Идентификатор чата у супергруппы отрицательный, с приставкой -100:
    | из ссылки https://t.me/c/4404612976/4 получается чат -1004404612976
    | и топик 4.
    |
    | Уровень, с которого шлём, задаётся у канала лога (LOG_TELEGRAM_LEVEL),
    | а не здесь: это дело журнала, а не бота.
    */
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),

        /*
        | Сколько ждать Телеграм. Эти секунды тратятся внутри запроса
        | покупателя, поэтому их мало: лучше не отправить оповещение,
        | чем заставить человека смотреть в белый экран.
        |
        | Соединение с боевого сервера устанавливается за 45 мс, так что
        | двух секунд на него хватает с сорокакратным запасом, а сорванную
        | попытку видно сразу — и остаётся время на повтор.
        */
        'timeout' => (float) env('TELEGRAM_TIMEOUT', 5),
        'connect_timeout' => (float) env('TELEGRAM_CONNECT_TIMEOUT', 2),

        /*
        | Попытки соединиться.
        |
        | С боевого сервера каждое пятое TCP-рукопожатие с api.telegram.org
        | пропадает: ICMP до него идёт без единой потери, а SYN на 443-й
        | порт выборочно не доходит — похоже на фильтрацию по пути.
        | Без повторов терялась бы пятая часть оповещений.
        */
        'tries' => (int) env('TELEGRAM_TRIES', 3),

        /*
        | Какой протокол использовать: v4, v6 или пусто — как решит система.
        | На боевом сервере обязателен v6: IPv4 до api.telegram.org там
        | не проходит вовсе, и без явного выбора curl половину попыток
        | тратит на мёртвый адрес.
        */
        'ip_version' => env('TELEGRAM_IP_VERSION'),

        /*
        | Топики группы. Пока нужен один; остальные добавляются сюда
        | и берутся по имени — App\Monitoring\Telegram::send($text, 'orders').
        */
        'topics' => [
            'errors' => env('TELEGRAM_TOPIC_ERRORS'),
            'payments' => env('TELEGRAM_TOPIC_PAYMENTS', 2),
            'leads' => env('TELEGRAM_TOPIC_LEADS', 13),
            'stock' => env('TELEGRAM_TOPIC_STOCK', 15),
            'users' => env('TELEGRAM_TOPIC_USERS', 17),
            'issues' => env('TELEGRAM_TOPIC_ISSUES', 19),
        ],

        'payments_connection' => env('TELEGRAM_PAYMENTS_CONNECTION', 'database'),

        /*
        | Окно молчания по одинаковым ошибкам, секунды. Сломанный шаг
        | оформления за минуту даёт сотню одинаковых сообщений, и группу
        | перестают читать ровно тогда, когда она нужнее всего.
        */
        'repeat_after' => (int) env('TELEGRAM_REPEAT_AFTER', 600),

        /*
        | Куда жаловаться, если оповещение не ушло. Обязательно канал
        | с файлом и обязательно мимо стопки: через неё жалоба вернулась бы
        | в тот же обработчик. Без этой записи несработавшее оповещение
        | не оставляло бы следа вообще — то есть ровно та тишина,
        | от которой всё и затевалось.
        */
        'fallback_channel' => env('TELEGRAM_FALLBACK_CHANNEL', 'daily'),
    ],

];
