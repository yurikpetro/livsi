{{--
    Общий шаблон транзакционных писем покупателю.

    Свёрстан таблицами и инлайновыми стилями — не из любви к прошлому веку:
    почтовые клиенты вырезают <style> из <head>, не понимают flex и grid,
    а Outlook рисует письмо движком Word. Всё, что здесь выглядит избыточным,
    держит письмо целым в Outlook, Mail.ru и Яндекс.Почте.

    Стиль брендовый, но сдержанный: письмо должно читаться и с выключенными
    картинками, поэтому логотип — текст, а не изображение.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('subject', 'LIVSI')</title>
</head>
<body style="margin:0; padding:0; background:#f2f1ec; font-family:-apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color:#000000;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f1ec;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                   style="width:600px; max-width:100%; background:#ffffff; border:1px solid #e5e4e0;">

                <tr>
                    <td style="padding:28px 32px 0 32px;">
                        <a href="{{ url('/') }}"
                           style="font-size:18px; font-weight:700; letter-spacing:0.22em; text-transform:uppercase; color:#000000; text-decoration:none;">
                            LIVSI
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 32px 32px 32px;">
                        <h1 style="margin:0 0 16px 0; font-size:24px; line-height:1.15; font-weight:700; text-transform:uppercase; letter-spacing:-0.01em;">
                            @yield('title')
                        </h1>

                        @yield('body')
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 32px 28px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-top:1px solid #e5e4e0; padding-top:18px; font-size:11px; line-height:1.6; color:#6a6a65;">
                                    @yield('footnote')

                                    {{-- Реквизиты продавца обязательны при дистанционной
                                         торговле. Композитор отдаёт их всем видам, письма
                                         не исключение; профиль не заполнен — пишем только
                                         название, а не выдуманный ИНН. --}}
                                    <p style="margin:0 0 8px 0;">
                                        {{ $siteSeller?->legal_name ?? 'LIVSI' }}@if ($siteSeller?->inn), ИНН {{ $siteSeller->inn }}@endif
                                    </p>

                                    <p style="margin:0;">
                                        <a href="{{ route('contacts') }}" style="color:#6a6a65;">Контакты</a> ·
                                        <a href="{{ route('legal.delivery') }}" style="color:#6a6a65;">Доставка и оплата</a> ·
                                        <a href="{{ route('legal.returns') }}" style="color:#6a6a65;">Возврат</a> ·
                                        <a href="{{ route('legal.privacy') }}" style="color:#6a6a65;">Политика конфиденциальности</a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:100%;">
                <tr>
                    <td style="padding:16px 8px; font-size:11px; line-height:1.6; color:#6a6a65;">
                        {{-- Письмо транзакционное: оно приходит по факту заказа,
                             а не по подписке, и отписки в нём быть не должно.
                             Рекламные рассылки — отдельный канал с отдельным
                             согласием (38-ФЗ «О рекламе», ст. 18). --}}
                        Это письмо отправлено по вашему заказу на сайте LIVSI.
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>

</body>
</html>
