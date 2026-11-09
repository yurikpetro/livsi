{{-- Менеджеру: заявка на возврат. Без вёрстки — состав, причина и ссылка в админку. --}}
@php use App\Support\Money; @endphp

<p><strong>Клиент просит вернуть заказ {{ $return->order->number }}</strong></p>

<p>
    Заявка: {{ $return->number }}<br>
    Причина: {{ $return->reasonLabel() }}<br>
    Покупатель: {{ $return->order->customer_name }}<br>
    Телефон: {{ $return->order->customer_phone }}<br>
    Почта: {{ $return->order->customer_email }}
</p>

<p>
    Возвращает:<br>
    @foreach ($return->items as $item)
        {{ $item['label'] }} × {{ $item['qty'] }} — {{ Money::rub((int) $item['amount']) }}<br>
    @endforeach
</p>

@if (filled($return->comment))
    <p>Комментарий покупателя:<br>{!! nl2br(e($return->comment)) !!}</p>
@endif

@if (! empty($return->photos))
    <p>Фото: {{ count($return->photos) }} — в карточке заказа.</p>
@endif

<p>Деньги по закону — не позднее {{ $return->refundDeadline()->timezone('Europe/Moscow')->format('d.m.Y') }}.</p>

<p><a href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('edit', ['record' => $return->order], panel: 'admin') }}">Открыть заказ в админке</a></p>
