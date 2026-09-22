@extends('mail.layout')

@section('subject', 'Заказ ' . $order->number . ' отменён')
@section('title', 'Оплата не прошла')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $order->customer_name }}, оплата по заказу
        <strong>{{ $order->number }}</strong> не прошла, и мы его отменили.
        Деньги не списаны.
    </p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Товары вернулись в продажу. Если заказ всё ещё нужен — соберите его
        заново, это займёт минуту.
    </p>

    @include('mail.partials.order-lines', ['order' => $order])

    @include('mail.partials.button', ['url' => route('catalog.index'), 'label' => 'Перейти в каталог'])
@endsection

@section('footnote')
    <p style="margin:0 0 8px 0;">
        Если деньги всё-таки списались — напишите нам, разберёмся.
    </p>
@endsection
