@extends('layouts.app')

@section('title', 'Оформление заказа — LIVSI')

@php
    use App\Support\Money;

    $gift = $cart->giftItem();
@endphp

@section('content')
    <section class="lead-section">
        <div class="site-container lead-layout py-16 md:py-24">
            <div>
                <div><span class="lead-eyebrow">Оформление заказа</span></div>
                <h1 class="lead-title">Почти<br>готово</h1>
                <p class="lead-lede">
                    Оставьте контакты — после оплаты отправим кассовый чек на почту
                    и ссылку на заказ.
                </p>

                {{-- Состав заказа виден рядом с формой: человек должен видеть,
                     за что платит, не возвращаясь в корзину. --}}
                <div class="mt-10 border-t border-ink">
                    @foreach ($cart->paidItems() as $item)
                        <div class="flex items-baseline justify-between gap-4 border-b border-line py-3 text-sm">
                            <span>
                                {{ $item->variant->product->title }}
                                <span class="text-muted">· {{ $item->variant->storefrontLabel() }}</span>
                                @if ($item->qty > 1)
                                    <span class="text-muted">× {{ $item->qty }}</span>
                                @endif
                            </span>
                            <span class="font-bold whitespace-nowrap">{{ Money::rub($item->lineTotal()) }}</span>
                        </div>
                    @endforeach

                    @if ($gift)
                        <div class="flex items-baseline justify-between gap-4 border-b border-line py-3 text-sm">
                            <span>
                                Подарок: {{ $gift->variant->product->title }}
                                <span class="text-muted">· {{ $gift->variant->storefrontLabel() }}</span>
                            </span>
                            <span class="font-bold text-green whitespace-nowrap">−{{ Money::rub($gift->variant->price) }}</span>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 py-4">
                        <span class="text-[10px] uppercase tracking-[0.08em] text-muted">К оплате</span>
                        <span class="text-2xl font-black">
                            {{ Money::rub(max(0, $cart->subtotal() - ($gift ? $gift->variant->price : 0))) }}
                        </span>
                    </div>
                </div>

                <p class="mt-2 text-xs leading-relaxed text-muted">
                    Стоимость доставки рассчитывается отдельно и оплачивается при получении.
                </p>
            </div>

            <div>
                @unless ($gateway->isConfigured())
                    {{-- Честное состояние вместо кнопки, которая ничего не сделает. --}}
                    <div class="mb-6 border-l-4 border-danger bg-paper p-5 text-sm leading-relaxed" role="alert">
                        Приём оплаты ещё не настроен. Напишите нам — примем заказ вручную.
                    </div>
                @endunless

                <form method="POST" action="{{ route('checkout.store') }}" class="lead-form">
                    @csrf

                    @error('cart')
                        <p class="field-error" role="alert">{{ $message }}</p>
                    @enderror
                    @error('payment')
                        <p class="field-error" role="alert">{{ $message }}</p>
                    @enderror

                    <label class="field">
                        <span class="field-label">Имя <span aria-hidden="true">*</span></span>
                        <input type="text" name="name" value="{{ old('name') }}" required
                               placeholder="Как к вам обращаться" autocomplete="name" class="field-control">
                        @error('name')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <label class="field">
                        <span class="field-label">Телефон <span aria-hidden="true">*</span></span>
                        <input type="tel" name="phone" value="{{ old('phone') }}" required
                               placeholder="+7 900 000-00-00" autocomplete="tel" class="field-control">
                        @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <label class="field field-wide">
                        <span class="field-label">Электронная почта <span aria-hidden="true">*</span></span>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               placeholder="Куда отправить чек" autocomplete="email" class="field-control">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                        <span class="mt-1 block text-[11px] text-muted">На этот адрес придёт кассовый чек — этого требует закон.</span>
                    </label>

                    <label class="field field-wide">
                        <span class="field-label">Комментарий</span>
                        <textarea name="comment" rows="3" placeholder="Пожелания к заказу"
                                  class="field-control">{{ old('comment') }}</textarea>
                        @error('comment')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <label class="consent">
                        <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                        <span>
                            Принимаю <a href="{{ route('legal.offer') }}" target="_blank" class="underline hover:text-ink">условия оферты</a>
                            и согласен на <a href="{{ route('legal.consent') }}" target="_blank" class="underline hover:text-ink">обработку персональных данных</a>
                            @error('consent')<span class="field-error mt-1 block">{{ $message }}</span>@enderror
                        </span>
                    </label>

                    <label class="consent">
                        <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent'))>
                        <span>Согласен получать новости и предложения LIVSI — по желанию</span>
                    </label>

                    <button type="submit" class="btn btn-dark" @disabled(! $gateway->isConfigured())>
                        Перейти к оплате
                        <span aria-hidden="true">→</span>
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection
