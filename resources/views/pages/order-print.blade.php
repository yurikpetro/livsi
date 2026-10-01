<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Заказ {{ $order->number }} — печать</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { max-width: 210mm; margin: 0 auto; padding: 24px; color: #242424; background: #f4f4f1; font: 14px/1.45 Arial, sans-serif; }
        .sheet { min-height: 270mm; padding: 32px; background: #fff; }
        .toolbar { display: flex; justify-content: flex-end; gap: 12px; margin-bottom: 16px; }
        .toolbar button, .toolbar a { padding: 10px 16px; border: 1px solid #51a940; border-radius: 6px; background: #fff; color: #245b1b; font: inherit; text-decoration: none; cursor: pointer; }
        .toolbar button { background: #51a940; color: #fff; }
        header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding-bottom: 20px; border-bottom: 2px solid #51a940; }
        .brand { color: #51a940; font-size: 24px; font-weight: 700; font-style: italic; }
        h1 { margin: 22px 0 4px; font-size: 25px; line-height: 1.2; }
        h2 { margin: 26px 0 10px; font-size: 16px; }
        p { margin: 4px 0; }
        .muted { color: #626262; }
        .small { font-size: 12px; }
        .status { padding: 6px 10px; border: 1px solid #b7d7af; border-radius: 4px; color: #245b1b; font-weight: 700; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px; }
        dl { margin: 0; }
        .details dl div, .totals div { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; }
        dt { color: #626262; }
        dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 9px 6px; border-bottom: 1px solid #ddd; vertical-align: top; text-align: left; overflow-wrap: anywhere; }
        th { border-top: 1px solid #888; font-size: 11px; text-transform: uppercase; }
        th:nth-child(1) { width: 12%; }
        th:nth-child(2) { width: 34%; }
        th:nth-child(3) { width: 8%; }
        th:nth-child(4), th:nth-child(5), th:nth-child(6) { width: 15%; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 290px; max-width: 100%; margin: 16px 0 0 auto; }
        .totals .grand { border-top: 2px solid #242424; font-size: 17px; font-weight: 700; }
        .note { margin-top: 24px; padding: 12px; background: #f4f4f1; white-space: pre-wrap; overflow-wrap: anywhere; }
        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #ddd; color: #626262; font-size: 11px; }
        tr { break-inside: avoid; }
        @media print {
            body { max-width: none; margin: 0; padding: 0; background: #fff; print-color-adjust: exact; }
            .sheet { min-height: 0; padding: 0; }
            .toolbar { display: none; }
        }
        @media (max-width: 640px) {
            body { padding: 12px; }
            .sheet { min-height: 0; padding: 18px; }
            .details { grid-template-columns: 1fr; }
            table { font-size: 11px; }
            th, td { padding: 5px 3px; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('edit', ['record' => $order]) }}">Вернуться к заказу</a>
        <button type="button" onclick="window.print()">Печать</button>
    </div>

    <main class="sheet">
        <header>
            <div>
                <div class="brand">LIVSI</div>
                <p class="muted small">Внутренний лист заказа · не кассовый чек</p>
            </div>
            <div class="status">{{ $order->statusLabel() }}</div>
        </header>

        <h1>Заказ {{ $order->number }}</h1>
        <p class="muted">Оформлен {{ $order->created_at->timezone('Europe/Moscow')->format('d.m.Y в H:i') }} МСК</p>

        <div class="details">
            <section>
                <h2>Покупатель</h2>
                <dl>
                    <div><dt>Имя</dt><dd>{{ $order->customer_name }}</dd></div>
                    <div><dt>Телефон</dt><dd>{{ $order->customer_phone }}</dd></div>
                    <div><dt>Почта</dt><dd>{{ $order->customer_email }}</dd></div>
                </dl>
            </section>
            <section>
                <h2>Оплата и доставка</h2>
                <dl>
                    <div><dt>Платёж</dt><dd>{{ $payment?->statusLabel() ?? 'Не начинался' }}</dd></div>
                    @if ($order->paid_at)
                        <div><dt>Оплачен</dt><dd>{{ $order->paid_at->timezone('Europe/Moscow')->format('d.m.Y в H:i') }} МСК</dd></div>
                    @endif
                    <div><dt>Доставка</dt><dd>{{ \App\Services\DeliverySelection::label($order->delivery_method) }}</dd></div>
                    @if ($order->delivery_city)
                        <div><dt>Город</dt><dd>{{ $order->delivery_city }}</dd></div>
                    @endif
                    @if ($order->delivery_region)
                        <div><dt>Регион</dt><dd>{{ $order->delivery_region }}</dd></div>
                    @endif
                    @if ($order->delivery_address)
                        <div><dt>Адрес</dt><dd>{{ $order->delivery_address }}</dd></div>
                    @endif
                    @if ($order->delivery_point_address)
                        <div><dt>Предпочтительный ПВЗ</dt><dd>{{ $order->delivery_point_address }}</dd></div>
                    @elseif ($order->delivery_point_hint)
                        <div><dt>Пожелание по ПВЗ</dt><dd>{{ $order->delivery_point_hint }}</dd></div>
                    @endif
                    <div><dt>Стоимость доставки</dt><dd>{{ $order->delivery_price > 0 ? \App\Support\Money::rub($order->delivery_price) : 'Не согласована' }}</dd></div>
                </dl>
            </section>
        </div>

        <section>
            <h2>Состав заказа</h2>
            <table>
                <thead>
                    <tr>
                        <th>Артикул</th>
                        <th>Товар</th>
                        <th class="num">Кол-во</th>
                        <th class="num">Цена</th>
                        <th class="num">Скидка</th>
                        <th class="num">Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>{{ $item->sku }}</td>
                            <td>{{ $item->label() }} @if ($item->is_gift)<strong>· подарок</strong>@endif</td>
                            <td class="num">{{ $item->quantity }}</td>
                            <td class="num">{{ \App\Support\Money::rub($item->unit_price) }}</td>
                            <td class="num">{{ $item->discount > 0 ? '−'.\App\Support\Money::rub($item->discount) : '—' }}</td>
                            <td class="num">{{ \App\Support\Money::rub($item->total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="totals">
                <div><span>Товары</span><strong>{{ \App\Support\Money::rub($order->items_total) }}</strong></div>
                @if ($order->discount_total > 0)
                    <div><span>Скидка</span><strong>−{{ \App\Support\Money::rub($order->discount_total) }}</strong></div>
                @endif
                @if ($order->delivery_price > 0)
                    <div><span>Доставка</span><strong>{{ \App\Support\Money::rub($order->delivery_price) }}</strong></div>
                @endif
                <div class="grand"><span>Итого по заказу</span><span>{{ \App\Support\Money::rub($order->total) }}</span></div>
            </div>
        </section>

        @if ($order->comment)
            <section class="note"><strong>Комментарий покупателя</strong><br>{{ $order->comment }}</section>
        @endif

        <footer>Распечатано {{ now()->timezone('Europe/Moscow')->format('d.m.Y в H:i') }} МСК · LIVSI</footer>
    </main>
</body>
</html>
