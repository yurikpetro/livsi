@extends('layouts.app')

@section('title', 'Личный кабинет — LIVSI')

@php use App\Support\Money; @endphp

@section('content')
    <section class="site-container py-16 md:py-24">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <div class="eyebrow">Личный кабинет</div>
                <h1 class="mt-4 text-3xl md:text-4xl">{{ $user->name }}</h1>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-[11px] font-bold uppercase tracking-[0.08em] text-muted hover:text-ink">
                    Выйти
                </button>
            </form>
        </div>

        <div class="mt-12 grid gap-12 md:grid-cols-[1fr_20rem]">
            <div>
                <div class="eyebrow">Заказы</div>

                @if ($orders->isEmpty())
                    <p class="mt-5 border-l-4 border-line bg-shell px-5 py-4 text-sm leading-relaxed">
                        Заказов пока нет.
                        <a href="{{ route('catalog.index') }}" class="underline hover:text-green">Перейти в каталог</a>.
                    </p>
                @else
                    <div class="mt-5 border-t border-ink">
                        @foreach ($orders as $order)
                            <div class="border-b border-line py-5">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <a href="{{ route('order.show', $order->number) }}" class="font-bold hover:text-green">
                                        Заказ {{ $order->number }}
                                    </a>
                                    <span class="text-sm text-muted">
                                        {{ $order->created_at->translatedFormat('j F Y') }}
                                        · {{ $order->statusLabel() }}
                                    </span>
                                </div>

                                <p class="mt-2 text-sm leading-relaxed text-muted">
                                    {{ $order->items->map(fn ($i) => $i->label() . ($i->quantity > 1 ? ' × ' . $i->quantity : ''))->implode(', ') }}
                                </p>

                                <div class="mt-3 flex flex-wrap items-center gap-4">
                                    <span class="font-bold">{{ Money::rub($order->total) }}</span>

                                    {{-- Повтор заказа — ключевой сценарий для мастеров:
                                         они берут одно и то же раз в месяц. --}}
                                    <form method="POST" action="{{ route('account.repeat', $order) }}">
                                        @csrf
                                        <button type="submit" class="text-[11px] font-bold uppercase tracking-[0.08em] hover:text-green">
                                            Повторить заказ
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-8">{{ $orders->links() }}</div>
                @endif
            </div>

            <aside>
                <div class="eyebrow">Данные</div>

                <form method="POST" action="{{ route('account.update') }}" class="mt-5">
                    @csrf
                    @method('PATCH')

                    <label class="field">
                        <span class="field-label">Имя</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                               autocomplete="name" class="field-control">
                        @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <label class="field mt-4">
                        <span class="field-label">Телефон</span>
                        <input type="tel" name="phone" value="{{ old('phone', $user->phone) }}"
                               placeholder="+7 900 000-00-00" autocomplete="tel" class="field-control">
                        @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <button type="submit" class="btn btn-dark mt-5">Сохранить</button>
                </form>

                {{-- Почта не правится: по ней уходит кассовый чек, и она же
                     связывает аккаунт со входом через провайдера. Смена адреса
                     — это смена способа входа, отдельный разговор. --}}
                <dl class="legal-requisites mt-8">
                    <div><dt>Почта</dt><dd>{{ $user->email }}</dd></div>
                    @if ($user->socialAccounts->isNotEmpty())
                        <div>
                            <dt>Вход</dt>
                            <dd>{{ $user->socialAccounts->pluck('provider')->map(fn ($p) => ucfirst($p))->implode(', ') }}</dd>
                        </div>
                    @endif
                </dl>

                <p class="mt-6 text-xs leading-relaxed text-muted">
                    Чтобы поменять почту, напишите нам — на неё уходит кассовый чек.
                </p>
            </aside>
        </div>
    </section>
@endsection
