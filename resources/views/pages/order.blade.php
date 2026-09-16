@extends('layouts.app')

@section('title', 'Заказ ' . $order->number . ' — LIVSI')

@php
    use App\Models\Order;
    use App\Support\Money;

    $state = match ($order->status) {
        Order::STATUS_PAID => [
            'Заказ оплачен',
            'Кассовый чек отправлен на ' . $order->customer_email . '. Менеджер свяжется по доставке.',
            'border-green',
        ],
        Order::STATUS_CANCELLED => [
            'Заказ отменён',
            'Оплата не прошла или срок ожидания истёк. Товары снова доступны в каталоге.',
            'border-danger',
        ],
        default => [
            'Ждём оплату',
            'Если платёжная страница закрылась, оплату можно продолжить по кнопке ниже.',
            'border-ink',
        ],
    };
@endphp

@section('content')
    <section class="site-container py-16 md:py-24">
        <div class="eyebrow">Заказ {{ $order->number }} от {{ $order->created_at->translatedFormat('j F Y') }}</div>

        <div class="mt-6 border-l-4 {{ $state[2] }} bg-shell p-6 md:p-8" role="status">
            <h1 class="text-3xl md:text-4xl">{{ $state[0] }}</h1>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted">{{ $state[1] }}</p>

            @if (! $order->isPaid() && $order->status !== Order::STATUS_CANCELLED && $payment?->confirmation_url)
                <a href="{{ $payment->confirmation_url }}" class="btn btn-dark mt-6">
                    Продолжить оплату <span aria-hidden="true">→</span>
                </a>
            @endif
        </div>

        @guest
            @if ($canLinkAccount && $order->user_id === null)
                {{-- «Аккаунт предлагаем создать уже после оформления, в один клик»
                     — ответ заказчика, п. 1.1. Заказ привязывается сразу же:
                     право на него доказано токеном из этой самой ссылки. --}}
                <div class="mt-8 border border-line p-6 md:p-8">
                    <div class="eyebrow">Личный кабинет</div>
                    <h2 class="mt-3 text-2xl">Сохранить заказ за собой</h2>
                    <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted">
                        Тогда заказ не потеряется вместе со ссылкой, а в следующий раз
                        имя, телефон и почта подставятся сами. Регистрация не нужна —
                        вход через Яндекс за один шаг.
                    </p>

                    <form method="POST" action="{{ route('auth.yandex') }}" class="mt-6">
                        @csrf
                        <input type="hidden" name="order" value="{{ $order->number }}">
                        <input type="hidden" name="token" value="{{ $order->access_token }}">
                        <button type="submit" class="btn btn-dark">Войти через Яндекс</button>
                    </form>
                </div>
            @endif
        @endguest

        <div class="mt-12 grid gap-12 md:grid-cols-[1fr_20rem]">
            <div>
                <div class="eyebrow">Состав заказа</div>

                <div class="mt-5 border-t border-ink">
                    @foreach ($order->items as $item)
                        <div class="flex items-baseline justify-between gap-4 border-b border-line py-3 text-sm">
                            <span>
                                {{ $item->label() }}
                                @if ($item->quantity > 1)<span class="text-muted">× {{ $item->quantity }}</span>@endif
                                @if ($item->is_gift)<span class="text-green">· подарок</span>@endif
                            </span>
                            <span class="font-bold whitespace-nowrap">{{ Money::rub($item->total) }}</span>
                        </div>
                    @endforeach
                </div>

                <dl class="legal-requisites mt-8">
                    <div>
                        <dt>Товары</dt>
                        <dd>{{ Money::rub($order->items_total) }}</dd>
                    </div>
                    @if ($order->discount_total > 0)
                        <div>
                            <dt>Скидка за подарок</dt>
                            <dd>−{{ Money::rub($order->discount_total) }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>Итого оплачено</dt>
                        <dd class="font-bold">{{ Money::rub($order->total) }}</dd>
                    </div>
                </dl>
            </div>

            <aside class="self-start md:border-l md:border-line md:pl-8">
                <div class="eyebrow">Покупатель</div>
                <dl class="legal-requisites mt-5">
                    <div><dt>Имя</dt><dd>{{ $order->customer_name }}</dd></div>
                    <div><dt>Телефон</dt><dd>{{ $order->customer_phone }}</dd></div>
                    <div><dt>Почта</dt><dd>{{ $order->customer_email }}</dd></div>
                    @if ($payment)
                        <div><dt>Оплата</dt><dd>{{ $payment->statusLabel() }}</dd></div>
                    @endif
                </dl>

                <p class="mt-6 text-xs leading-relaxed text-muted">
                    Сохраните ссылку на эту страницу — по ней заказ открывается без входа.
                    Условия возврата — на странице
                    <a href="{{ route('legal.returns') }}" class="underline hover:text-ink">возврата товара</a>.
                </p>
            </aside>
        </div>
    </section>
@endsection
