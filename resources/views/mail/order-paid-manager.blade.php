{{--
    Письмо менеджеру. Без вёрстки: его читает сотрудник в рабочей почте,
    и ему нужен не дизайн, а состав, контакты и ссылка в админку.
--}}
@php use App\Support\Money; @endphp

<p><strong>Оплачен заказ {{ $order->number }} — {{ Money::rub($order->total) }}</strong></p>

<p>
    Покупатель: {{ $order->customer_name }}<br>
    Телефон: {{ $order->customer_phone }}<br>
    Почта: {{ $order->customer_email }}
</p>

<p>
    Доставка: {{ \App\Services\DeliverySelection::label($order->delivery_method) }}<br>
    @if ($order->delivery_city)Город: {{ $order->delivery_city }}<br>@endif
    @if ($order->delivery_region)Регион: {{ $order->delivery_region }}<br>@endif
    @if ($order->delivery_address)Адрес: {{ $order->delivery_address }}<br>@endif
    @if ($order->delivery_point_address)Предпочтительный ПВЗ: {{ $order->delivery_point_address }}<br>
    @elseif ($order->delivery_point_hint)Пожелание по ПВЗ: {{ $order->delivery_point_hint }}<br>@endif
    Стоимость и пункт выдачи нужно согласовать отдельно.
</p>

<p>
    Состав:<br>
    @foreach ($order->items as $item)
        {{ $item->label() }} × {{ $item->quantity }} — {{ Money::rub($item->total) }}@if ($item->is_gift) (подарок)@endif<br>
    @endforeach
</p>

@if ($order->comment)
    <p>
        Комментарий покупателя:<br>
        {!! nl2br(e($order->comment)) !!}
    </p>
@endif

<p>
    Оплачен: {{ $order->paid_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
</p>

<p>
    <a href="{{ url('/admin/orders/' . $order->id . '/edit') }}">Открыть заказ в админке</a>
</p>
