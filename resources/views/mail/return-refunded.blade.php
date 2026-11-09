@extends('mail.layout')

@php use App\Support\Money; @endphp

@section('subject', 'Деньги за возврат по заказу ' . $return->order->number)
@section('title', 'Деньги возвращены')

@section('body')
    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">
        {{ $return->order->customer_name }}, мы вернули <strong>{{ Money::rub((int) $return->refund_amount) }}</strong>
        за возврат по заказу <strong>{{ $return->order->number }}</strong>.
    </p>

    @if ($return->deduction() > 0)
        <p style="margin:0 0 16px 0; font-size:13px; line-height:1.6; color:#6a6a65;">
            Удержано за обратную доставку: {{ Money::rub($return->deduction()) }}.
        </p>
    @endif

    <p style="margin:0; font-size:13px; line-height:1.6; color:#6a6a65;">
        Деньги придут тем же способом, которым вы платили. Срок зачисления зависит от банка.
        Чек возврата придёт отдельным письмом.
    </p>

    @include('mail.partials.return-lines', ['return' => $return])
@endsection
