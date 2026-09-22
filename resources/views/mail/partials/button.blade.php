{{--
    Кнопка-ссылка. В письме это таблица со ссылкой внутри, а не <a> с padding:
    Outlook игнорирует padding у строчных элементов, и кнопка схлопывается
    в подчёркнутый текст.

    Подключается через @include с параметрами url и label.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;">
    <tr>
        <td style="background:#000000;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:14px 26px; font-size:12px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#ffffff; text-decoration:none;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
