@extends('mail.layout')

@php use App\Models\OrderReturn; use App\Support\Money; @endphp

@section('subject', 'Возврат по заказу ' . $return->order->number . ' одобрен')
@section('title', 'Возврат одобрен')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $return->order->customer_name }}, возврат по заказу <strong>{{ $return->order->number }}</strong> одобрен.
        Вот как передать товар.
    </p>

    @if ($return->method === OrderReturn::METHOD_CDEK)
        <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6;">
            Отнесите товар в пункт СДЭК:<br>
            <strong>{{ $return->cdek_point_address ?: $return->cdek_point }}</strong>
        </p>
        <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6;">
            Скажите сотруднику, что это <strong>клиентский возврат</strong>, и назовите номер
            <strong>{{ $return->shipment_number }}</strong>. Возьмите паспорт — по нему проверят отправителя.
            Посылку упакует сотрудник пункта.
        </p>
    @elseif ($return->method === OrderReturn::METHOD_COURIER)
        <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6;">
            Товар заберёт наш курьер — он позвонит по номеру {{ $return->order->customer_phone }}, чтобы договориться о времени.
        </p>
    @endif

    @if (filled($return->instructions))
        <p style="margin:0 0 12px 0; font-size:14px; line-height:1.6;">{!! nl2br(e($return->instructions)) !!}</p>
    @endif

    <p style="margin:0 0 12px 0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Положите товар в заводской упаковке.
        @if ($return->deduction() > 0)
            Обратная доставка — за счёт покупателя: {{ Money::rub($return->deduction()) }} удержим из суммы возврата.
        @elseif ($return->buyerPaysShipping() && $return->method === OrderReturn::METHOD_CDEK)
            Обратная доставка — за счёт покупателя: стоимость СДЭК удержим из суммы возврата.
        @else
            Обратная доставка — за наш счёт.
        @endif
    </p>

    @include('mail.partials.return-lines', ['return' => $return])

    <p style="margin:16px 0 0 0; font-size:14px; line-height:1.6;">
        Когда товар придёт и мы его проверим, вернём <strong>{{ Money::rub($return->expectedRefund()) }}</strong>
        тем же способом, которым вы платили.
    </p>

    @include('mail.partials.button', ['url' => $return->order->accessUrl(), 'label' => 'Открыть заказ'])
@endsection

@section('footnote')
    <p style="margin:0 0 8px 0;">Номер заявки: {{ $return->number }}. Если что-то не получается — напишите нам, поможем.</p>
@endsection
