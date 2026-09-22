@extends('mail.layout')

@section('subject', 'Заказ ' . $order->number . ' принят')
@section('title', 'Заказ принят')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $order->customer_name }}, спасибо за заказ. Мы ждём оплату —
        как только она пройдёт, соберём и отправим.
    </p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Номер заказа — <strong style="color:#000000;">{{ $order->number }}</strong>,
        оформлен {{ $order->created_at->timezone(config('app.timezone'))->format('d.m.Y в H:i') }}.
    </p>

    @include('mail.partials.order-lines', ['order' => $order])

    @include('mail.partials.button', [
        'url'   => $order->accessUrl(),
        'label' => $order->isPaid() ? 'Открыть заказ' : 'Перейти к оплате',
    ])
@endsection

@section('footnote')
    {{-- Ссылка на заказ заменяет гостю личный кабинет, поэтому о ней
         нужно сказать прямо: без письма он потеряет заказ. --}}
    <p style="margin:0 0 8px 0;">
        Сохраните это письмо: по ссылке из него заказ открывается без входа на сайт.
    </p>
@endsection
