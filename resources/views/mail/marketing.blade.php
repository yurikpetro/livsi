@extends('mail.layout')

@section('subject', $mailing->subject)
@section('title', $mailing->subject)

@section('body')
    @if ($name)
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">{{ $name }}, здравствуйте!</p>
    @endif

    @foreach (preg_split('/\R{2,}/u', trim($mailing->body)) as $paragraph)
        <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach

    @if ($promo)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px 0;">
            <tr>
                <td style="padding:18px 20px; background:#f2f1ec; border:1px solid #e5e4e0;">
                    <p style="margin:0 0 6px 0; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#6a6a65;">Ваш промокод</p>
                    <p style="margin:0 0 6px 0; font-size:22px; font-weight:700; letter-spacing:0.08em;">{{ $promo->code }}</p>
                    <p style="margin:0; font-size:13px; line-height:1.5;">
                        {{ $promo->benefit() }}@if ($promo->expires_at) · до {{ $promo->expires_at->timezone('Europe/Moscow')->translatedFormat('j F Y') }}@endif.
                        Введите его в поле «Промокод» при оформлении заказа.
                    </p>
                </td>
            </tr>
        </table>
    @endif

    @include('mail.partials.button', ['url' => route('catalog.index'), 'label' => 'Перейти в каталог'])
@endsection

@section('outer')
    Вы получили это письмо, потому что согласились получать новости и предложения LIVSI.
    <a href="{{ $unsubscribeUrl }}" style="color:#6a6a65;">Отписаться</a>
@endsection
