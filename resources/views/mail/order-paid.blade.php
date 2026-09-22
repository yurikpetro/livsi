@extends('mail.layout')

@section('subject', 'Заказ ' . $order->number . ' оплачен')
@section('title', 'Оплата прошла')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $order->customer_name }}, оплата получена. Заказ
        <strong>{{ $order->number }}</strong> передан в сборку — напишем,
        когда он отправится.
    </p>

    @include('mail.partials.order-lines', ['order' => $order])

    @include('mail.partials.button', ['url' => $order->accessUrl(), 'label' => 'Открыть заказ'])
@endsection

@section('footnote')
    {{-- Чек выпускает оператор фискальных данных по данным, которые мы
         передали в платёж, — своим письмом его дублировать нельзя:
         юридическую силу имеет именно чек от оператора. --}}
    <p style="margin:0 0 8px 0;">
        Кассовый чек придёт отдельным письмом от оператора фискальных данных.
        Вернуть товар можно в течение 7 дней — условия на странице
        <a href="{{ route('legal.returns') }}" style="color:#6a6a65;">возврата</a>.
    </p>
@endsection
