@extends('mail.layout')

@section('subject', 'Возврат по заказу ' . $return->order->number)
@section('title', 'В возврате отказано')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $return->order->customer_name }}, мы рассмотрели заявку на возврат по заказу
        <strong>{{ $return->order->number }}</strong> и не можем её принять.
    </p>

    @if (filled($return->reject_reason))
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">Причина: {!! nl2br(e($return->reject_reason)) !!}</p>
    @endif

    <p style="margin:0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Если вы не согласны или есть подробности, которых мы не знаем, — напишите нам, разберёмся.
        Условия — на странице <a href="{{ route('legal.returns') }}" style="color:#6a6a65;">«Возврат товара»</a>.
    </p>

    @include('mail.partials.button', ['url' => route('contacts'), 'label' => 'Контакты'])
@endsection
