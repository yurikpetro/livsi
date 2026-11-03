@extends('layouts.app')

@section('title', 'Оформление заказа — LIVSI')

@php
    use App\Support\Money;
    use App\Services\DeliverySelection;

    $gift = $cart->giftItem();
    $goods = max(0, $cart->subtotal() - ($gift ? $gift->variant->price : 0));
    $toPay = $goods - ($promo['discount'] ?? 0);
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

                    @if ($promo && $promo['discount'] > 0)
                        <div class="flex items-baseline justify-between gap-4 border-b border-line py-3 text-sm">
                            <span>Промокод {{ $promo['code'] }} <span class="text-muted">· {{ mb_strtolower($promo['title']) }}</span></span>
                            <span class="font-bold text-green whitespace-nowrap">−{{ Money::rub($promo['discount']) }}</span>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 border-b border-line py-3 text-sm">
                        <span>Доставка</span>
                        <span class="text-right font-bold" data-checkout-delivery-price aria-live="polite">{{ $remainingToFreeShipping === 0 || ($promo['free_delivery'] ?? false) ? 'Бесплатно' : 'Не рассчитана' }}</span>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 py-4">
                        <span class="text-[10px] uppercase tracking-[0.08em] text-muted">К оплате</span>
                        <span class="text-2xl font-black" data-checkout-total data-items-total="{{ $toPay }}">
                            {{ Money::rub($toPay) }}
                        </span>
                    </div>
                </div>

                {{-- Промокод. Кнопки отправляют форму оформления (атрибут form),
                     чтобы введённые имя, почта и телефон не потерялись. --}}
                <div class="mt-4" data-checkout-promo>
                    @if ($promo)
                        <div class="flex flex-wrap items-center justify-between gap-3 border border-line px-4 py-3 text-sm">
                            <span>Промокод <strong>{{ $promo['code'] }}</strong> применён: {{ mb_strtolower($promo['title']) }}</span>
                            <button type="submit" form="checkout-form" formaction="{{ route('checkout.promo.remove') }}" formnovalidate
                                    class="text-[11px] font-bold uppercase tracking-[0.08em] hover:text-green">Убрать</button>
                        </div>
                    @else
                        <label class="field-label" for="promo_code">Промокод</label>
                        <div class="mt-1 flex gap-2">
                            <input id="promo_code" name="promo_code" form="checkout-form" value="{{ old('promo_code') }}" maxlength="40"
                                   autocomplete="off" class="field-control" placeholder="Если есть">
                            <button type="submit" form="checkout-form" formaction="{{ route('checkout.promo') }}" formnovalidate class="btn btn-outline">Применить</button>
                        </div>
                    @endif
                    @error('promo_code')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                    @if (session('promo_lost'))<span class="field-error" role="alert">Промокод снят: {{ session('promo_lost') }}</span>@endif
                </div>

                <p class="mt-2 text-xs leading-relaxed text-muted" data-checkout-delivery-note>
                    @if ($remainingToFreeShipping === 0)
                        Доставка бесплатная для вашего заказа. Выберите пункт выдачи, чтобы уточнить срок доставки.
                    @elseif ($deliveryCalculationEnabled)
                        Доставка рассчитается после выбора пункта выдачи и войдёт в сумму к оплате.
                    @else
                        Расчёт доставки временно недоступен. Оформление заказа станет доступно после восстановления расчёта.
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

                <form method="POST" action="{{ route('checkout.store') }}" id="checkout-form" class="lead-form"
                      @if ($deliveryCalculationEnabled) data-delivery-quote-url="{{ route('delivery.quote') }}" @endif>
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
                        @if ($ozonClientCheckEnabled)
                            <span class="mt-1 block text-[11px] text-muted" data-ozon-check-status>Для доставки Ozon укажите телефон получателя.</span>
                        @endif
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
                            <label class="delivery-choice" data-pickup-choice>
                                <input type="radio" name="delivery_method" value="{{ DeliverySelection::PICKUP_POINT }}" required
                                       data-points-search aria-haspopup="dialog" aria-controls="pickup-points-dialog"
                                       @checked(old('delivery_method') === DeliverySelection::PICKUP_POINT || in_array(old('delivery_method'), DeliverySelection::pickupMethods(), true))>
                                <span class="delivery-choice-copy">
                                    <strong>В пункт выдачи</strong>
                                    <small data-pickup-description>{{ $mapsApiKey ? 'Все доступные службы на одной карте' : 'Все доступные службы в общем списке' }}</small>
                                    <small data-point-selected aria-live="polite" hidden></small>
                                    <span class="delivery-choice-action" data-pickup-action>Выбрать пункт →</span>
                                </span>
                            </label>
                            <label class="delivery-choice" data-delivery-courier>
                                <input type="radio" name="delivery_method" value="{{ DeliverySelection::STAVROPOL_COURIER }}" required
                                       disabled>
                                <span><strong>Курьер по Ставрополю</strong><small>Временно недоступен</small></span>
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

                    <div class="contents" data-delivery-points
                         @if ($ozonClientCheckEnabled) data-ozon-check-url="{{ route('delivery.ozon.check-client') }}" @endif
                         data-courier-city="{{ config('livsi.delivery.origin_city') }}"
                         data-url="{{ route('delivery.points') }}"
                         data-geocode-url="{{ route('delivery.city.geocode') }}"
                         data-free-shipping="{{ $remainingToFreeShipping === 0 ? '1' : '0' }}"
                         data-methods="{{ implode(',', $pointMethods) }}"
                         data-maps-key="{{ $mapsApiKey }}" data-suggest-key="{{ $suggestApiKey }}">
                        <dialog id="pickup-points-dialog" class="pickup-dialog" data-points-dialog aria-label="Выбор пункта выдачи">
                            <div class="pickup-dialog-head">
                                <div><span class="pickup-dialog-kicker">ДОСТАВКА LIVSI</span><h2>Выберите пункт выдачи</h2><p data-points-location></p><p class="sr-only" data-points-instruction></p></div>
                                <button type="button" class="pickup-dialog-close" data-points-close aria-label="Закрыть карту">×</button>
                            </div>
                            <div class="pickup-dialog-body">
                                <aside class="pickup-sidebar">
                                    <p class="text-xs text-muted" role="status" data-points-status hidden></p>
                                    <label class="sr-only" for="pickup-filter">Поиск пункта выдачи</label>
                                    <input id="pickup-filter" type="search" class="pickup-filter" data-points-filter placeholder="Адрес, населённый пункт или служба">
                                    <fieldset class="pickup-carriers">
                                        <legend>Служба доставки</legend>
                                        @foreach (['ozon_point' => 'Ozon', 'cdek_point' => 'СДЭК', 'yandex_point' => 'Яндекс'] as $method => $label)
                                            @if (in_array($method, $pointMethods, true))
                                                <label class="pickup-carrier">
                                                    <input type="checkbox" value="{{ $method }}" data-points-carrier @checked($method !== 'ozon_point' || ! $ozonClientCheckEnabled) @disabled($method === 'ozon_point' && $ozonClientCheckEnabled)
                                                           @if ($method === 'ozon_point' && $ozonClientCheckEnabled) aria-describedby="ozon-eligibility-status" @endif>
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endif
                                        @endforeach
                                    </fieldset>
                                    @if ($ozonClientCheckEnabled)
                                        <p id="ozon-eligibility-status" class="pickup-count" role="status" data-ozon-check-status>Для доставки Ozon укажите телефон получателя.</p>
                                        <button type="button" class="pickup-more" data-ozon-check-retry hidden>Повторить проверку Ozon</button>
                                    @endif
                                    <p class="pickup-count" data-points-count role="status"></p>
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
                        <p class="mt-2 text-[11px] leading-relaxed text-muted" data-ozon-notice hidden>@if ($ozonClientCheckEnabled)Для доставки Ozon используйте проверенный номер получателя. При изменении номера пункт нужно выбрать заново.@else Для Ozon номер получателя должен быть привязан к Ozon ID.@endif</p>
                        @error('delivery_point_id')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    @if ($deliveryCalculationEnabled)
                        <div class="field field-wide" data-delivery-quote>
                            @if ($deliveryCalculationTest)
                                <p class="mb-2 text-xs text-muted">Пробный расчёт: используются временные размеры товаров и упаковки.</p>
                            @endif
                            <p class="text-sm" data-delivery-quote-status role="status">{{ $remainingToFreeShipping === 0 ? 'Доставка бесплатная. Выберите пункт выдачи, чтобы уточнить срок доставки.' : 'Выберите пункт выдачи для расчёта доставки.' }}</p>
                            <button type="button" class="btn btn-outline mt-2" data-delivery-recalculate>Рассчитать доставку</button>
                            <input type="hidden" name="delivery_quote_token" value="">
                            @error('delivery_quote')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                        </div>
                    @else
                        <p class="field-error field-wide" role="alert">Расчёт доставки временно недоступен. Попробуйте оформить заказ позже.</p>
                    @endif

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

                    <button type="submit" class="btn btn-dark" @disabled(! $gateway->isConfigured() || ! $deliveryCalculationEnabled)>
                        Перейти к оплате
                        <span aria-hidden="true">→</span>
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection
