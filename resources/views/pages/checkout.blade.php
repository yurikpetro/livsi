@extends('layouts.app')

@section('title', 'Оформление заказа — LIVSI')

@php
    use App\Support\Money;
    use App\Services\DeliverySelection;

    $gift = $cart->giftItem();
@endphp

@section('content')
    <section class="lead-section checkout-page">
        <div class="site-container lead-layout py-16 md:py-24">
            <div>
                <div><span class="lead-eyebrow">Последний шаг</span></div>
                <h1 class="lead-title">Оформление<br>заказа.</h1>
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
                    @if ($remainingToFreeShipping === 0)
                        Для этого заказа доставка бесплатная. Сейчас оплачиваются только товары; способ, пункт выдачи и срок менеджер подтвердит отдельно.
                    @elseif ($remainingToFreeShipping !== null)
                        До бесплатной доставки осталось {{ Money::rub($remainingToFreeShipping) }}. Сейчас оплачиваются только товары; стоимость и пункт выдачи менеджер согласует отдельно.
                    @else
                        Сейчас оплачиваются только товары. Стоимость и пункт выдачи менеджер согласует отдельно.
                    @endif
                </p>
            </div>

            <div>
                <x-development-notice />

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

                    <div class="field field-wide">
                        <button type="button" class="checkout-city-button" data-city-open aria-haspopup="dialog" aria-expanded="false" aria-controls="site-city-dialog"
                                @error('delivery_city') aria-invalid="true" aria-describedby="delivery-city-error" @enderror>
                            <span class="checkout-city-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            </span>
                            <span class="checkout-city-text">Выбрать город: <strong data-city-label>{{ old('delivery_city') ?: 'указать населённый пункт' }}</strong></span>
                            <span class="checkout-city-arrow" aria-hidden="true">→</span>
                        </button>
                        @error('delivery_city')<span id="delivery-city-error" class="field-error" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <input id="delivery-city" type="hidden" name="delivery_city" value="{{ old('delivery_city') }}">
                    <input type="hidden" name="delivery_region" value="{{ old('delivery_region') }}">

                    <div class="field field-wide" data-delivery-methods @if (mb_strlen(trim((string) old('delivery_city'))) < 2) hidden @endif>
                        <span class="field-label">Куда доставить? <span aria-hidden="true">*</span></span>
                        <div class="delivery-choices">
                            <label class="delivery-choice">
                                <input type="radio" name="delivery_method" value="{{ DeliverySelection::PICKUP_POINT }}" required
                                       @checked(old('delivery_method') === DeliverySelection::PICKUP_POINT || in_array(old('delivery_method'), DeliverySelection::pickupMethods(), true))>
                                <span><strong>В пункт выдачи</strong><small>{{ $mapsApiKey ? 'Все доступные службы на одной карте' : 'Все доступные службы в общем списке' }}</small></span>
                            </label>
                            <label class="delivery-choice" data-delivery-courier>
                                <input type="radio" name="delivery_method" value="{{ DeliverySelection::STAVROPOL_COURIER }}" required
                                       @disabled(! DeliverySelection::isStavropol((string) old('delivery_city')))
                                       @checked(old('delivery_method') === DeliverySelection::STAVROPOL_COURIER)>
                                <span><strong>Курьер по Ставрополю</strong><small>Доступен только в Ставрополе</small></span>
                            </label>
                        </div>
                        @error('delivery_method')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <label class="field field-wide" data-delivery-address>
                        <span class="field-label">Адрес для курьера по Ставрополю</span>
                        <input type="text" name="delivery_address" value="{{ old('delivery_address') }}"
                               placeholder="Улица, дом, квартира" autocomplete="street-address" class="field-control">
                        @error('delivery_address')<span class="field-error">{{ $message }}</span>@enderror
                    </label>

                    <input type="hidden" name="delivery_point_hint" value="{{ old('delivery_point_hint') }}">

                    <div class="field field-wide" data-delivery-points
                         data-courier-city="{{ config('livsi.delivery.origin_city') }}"
                         data-url="{{ route('delivery.points') }}"
                         data-geocode-url="{{ route('delivery.city.geocode') }}"
                         data-free-shipping="{{ $remainingToFreeShipping === 0 ? '1' : '0' }}"
                         data-methods="{{ implode(',', $pointMethods) }}"
                         data-maps-key="{{ $mapsApiKey }}" data-suggest-key="{{ $suggestApiKey }}">
                        <p class="mb-2 text-xs leading-relaxed text-muted" data-points-instruction></p>
                        <button type="button" class="btn btn-outline" data-points-search hidden>Выбрать пункт выдачи на карте</button>
                        <p class="text-xs text-muted" role="status" data-points-status hidden></p>
                        <p class="mt-2 text-sm font-semibold" data-point-selected hidden></p>
                        <dialog class="pickup-dialog" data-points-dialog aria-label="Выбор пункта выдачи">
                            <div class="pickup-dialog-head">
                                <div><span class="pickup-dialog-kicker">ДОСТАВКА LIVSI</span><h2>Выберите пункт выдачи</h2><p data-points-location></p></div>
                                <button type="button" class="pickup-dialog-close" data-points-close aria-label="Закрыть карту">×</button>
                            </div>
                            <div class="pickup-dialog-body">
                                <aside class="pickup-sidebar">
                                    <label class="sr-only" for="pickup-filter">Поиск пункта выдачи</label>
                                    <input id="pickup-filter" type="search" class="pickup-filter" data-points-filter placeholder="Адрес, населённый пункт или служба">
                                    <p class="pickup-count" data-points-count></p>
                                    <div class="pickup-list" data-points-list></div>
                                    <button type="button" class="pickup-more" data-points-more hidden>Показать ещё пункты</button>
                                </aside>
                                <div class="pickup-map-wrap">
                                    <div class="delivery-map" data-points-map hidden aria-label="Карта пунктов выдачи"></div>
                                    <p class="pickup-map-fallback" data-points-map-fallback hidden>Карта сейчас недоступна. Выберите пункт в списке слева.</p>
                                </div>
                            </div>
                        </dialog>
                        <input type="hidden" name="delivery_point_id" value="{{ old('delivery_point_id') }}" data-point-id>
                        <input type="hidden" name="delivery_point_method" value="{{ old('delivery_point_method') }}" data-point-method>
                        <p class="mt-2 text-[11px] leading-relaxed text-muted" data-ozon-notice hidden>Для Ozon номер получателя должен быть привязан к Ozon ID. Если это не так, менеджер предложит другой способ.</p>
                        @error('delivery_point_id')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

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
