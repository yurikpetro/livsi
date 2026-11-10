@extends('mail.layout')

@php use App\Models\OrderReturn; @endphp

@section('subject', 'Заявка на возврат по заказу ' . $return->order->number)
@section('title', 'Заявка на возврат принята')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $return->order->customer_name }}, мы получили заявку на возврат по заказу
        <strong>{{ $return->order->number }}</strong>. Причина: {{ mb_strtolower($return->reasonLabel()) }}.
        Рассмотрим её и ответим письмом — напишем, как передать товар.
    </p>

    @include('mail.partials.return-lines', ['return' => $return])

    <p style="margin:24px 0 8px 0; font-size:13px; line-height:1.6;">
        <strong>Что важно для возврата</strong>
    </p>
    <p style="margin:0 0 8px 0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Товар надлежащего качества принимаем, если сохранены его товарный вид, заводская упаковка и защитная плёнка.
        Вскрытая и использованная косметика считается бывшей в употреблении.
    </p>
    <p style="margin:0 0 8px 0; font-size:13px; line-height:1.6; color:#6a6a65;">
        @if ($return->isSellerFault())
            Раз товар с недостатком или пришёл не тот — обратную доставку оплачиваем мы.
        @else
            Обратную доставку товара, который не подошёл, оплачивает покупатель: если она платная, её стоимость удержим из суммы возврата.
            Точную сумму напишем, когда подтвердим возврат.
        @endif
        Деньги вернём тем же способом, которым вы платили, не позднее {{ OrderReturn::REFUND_DAYS }} дней со дня заявки.
    </p>
    <p style="margin:0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Подробно — на странице <a href="{{ route('legal.returns') }}" style="color:#6a6a65;">«Возврат товара»</a>.
    </p>

    @include('mail.partials.button', ['url' => $return->order->accessUrl(), 'label' => 'Открыть заказ'])
@endsection

@section('footnote')
    <p style="margin:0 0 8px 0;">Номер заявки: {{ $return->number }}.</p>
@endsection
