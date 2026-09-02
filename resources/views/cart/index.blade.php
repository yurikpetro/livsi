@extends('layouts.app')

@section('title', 'Корзина — LIVSI')

@section('content')
    @php use App\Support\Money; @endphp

    <section class="site-container py-14">
        <h1 class="text-4xl md:text-5xl">Корзина</h1>

        @if ($cart->isEmpty())
            <div class="py-20 text-center">
                <p class="text-lg font-bold">Пока пусто</p>
                <p class="mt-2 text-xs text-muted">Загляните в каталог — начните с бестселлеров.</p>
                <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-6">Перейти в каталог <span aria-hidden="true">→</span></a>
            </div>
        @else
            {{-- Прогресс до бесплатной доставки: механика из прототипа --}}
            @if ($remainingToFreeShipping !== null)
                @php
                    $threshold = app(\App\Services\PromoService::class)->freeShippingRule()?->threshold ?? 0;
                    $percent   = $threshold > 0 ? min(100, (int) round($subtotal / $threshold * 100)) : 100;
                @endphp
                <div class="mt-10 border border-line p-4">
                    <div class="flex items-center justify-between text-[11px] uppercase tracking-[0.08em]">
                        <span>
                            @if ($remainingToFreeShipping > 0)
                                До бесплатной доставки — {{ Money::rub($remainingToFreeShipping) }}
                            @else
                                Доставка бесплатно
                            @endif
                        </span>
                        <span class="text-muted">{{ $percent }}%</span>
                    </div>
                    <div class="mt-3 h-1 w-full bg-shell">
                        <div class="h-1 bg-neon" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endif

            <div class="mt-8 grid gap-12 lg:grid-cols-[1.6fr_1fr]">
                <div class="divide-y divide-line border-y border-line">
                    @foreach ($cart->items as $item)
                        <div class="flex gap-5 py-5">
                            @php $image = $item->variant->product->primaryImage(); @endphp

                            <a href="{{ route('catalog.show', $item->variant->product) }}" class="block w-24 shrink-0 bg-shell">
                                @if ($image)
                                    <x-img :path="$image->path" :alt="$image->alt" sizes="96px"
                                           class="aspect-[4/5] w-full object-cover" />
                                @endif
                            </a>

                            <div class="flex flex-1 flex-col">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                            {{ $item->variant->product->line?->title }}
                                            @if ($item->is_gift)
                                                <span class="ml-2 bg-neon px-1.5 py-0.5 text-ink">Подарок</span>
                                            @endif
                                        </div>
                                        <a href="{{ route('catalog.show', $item->variant->product) }}"
                                           class="mt-1 block text-sm font-bold hover:text-green">
                                            {{ $item->variant->product->title }}
                                        </a>
                                        <div class="mt-1 text-[11px] text-muted">{{ $item->variant->storefrontLabel() }}</div>
                                    </div>

                                    <div class="text-right">
                                        @if ($item->is_gift)
                                            <div class="text-xs text-muted line-through">{{ Money::rub($item->unitPrice()) }}</div>
                                            <div class="text-sm font-bold">0 ₽</div>
                                        @else
                                            <div class="text-sm font-bold">{{ Money::rub($item->lineTotal()) }}</div>
                                            @if ($item->qty > 1)
                                                <div class="text-[11px] text-muted">{{ Money::rub($item->unitPrice()) }} за шт.</div>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                @unless ($item->is_gift)
                                    <div class="mt-auto flex items-center gap-4 pt-4">
                                        <form method="POST" action="{{ route('cart.update', $item) }}" class="flex items-center border border-line">
                                            @csrf @method('PATCH')
                                            <button type="submit" name="qty" value="{{ $item->qty - 1 }}"
                                                    class="h-8 w-8 text-sm hover:bg-shell" aria-label="Уменьшить количество">−</button>
                                            <span class="w-8 text-center text-xs">{{ $item->qty }}</span>
                                            <button type="submit" name="qty" value="{{ $item->qty + 1 }}"
                                                    class="h-8 w-8 text-sm hover:bg-shell" aria-label="Увеличить количество">+</button>
                                        </form>

                                        <form method="POST" action="{{ route('cart.destroy', $item) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-[10px] uppercase tracking-[0.08em] text-muted underline hover:text-danger">
                                                Удалить
                                            </button>
                                        </form>

                                        @if ($item->variant->available() <= $item->qty)
                                            <span class="text-[10px] text-muted">Это всё, что осталось</span>
                                        @endif
                                    </div>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>

                <aside class="h-fit border border-line p-6">
                    <div class="flex items-baseline justify-between">
                        <span class="text-[11px] uppercase tracking-[0.08em] text-muted">Товары</span>
                        <span class="text-sm font-bold">{{ Money::rub($subtotal) }}</span>
                    </div>

                    <div class="mt-3 flex items-baseline justify-between">
                        <span class="text-[11px] uppercase tracking-[0.08em] text-muted">Доставка</span>
                        <span class="text-xs">{{ $remainingToFreeShipping === 0 ? 'Бесплатно' : 'Рассчитаем при оформлении' }}</span>
                    </div>

                    <div class="mt-6 flex items-baseline justify-between border-t border-line pt-4">
                        <span class="text-sm font-bold uppercase">Итого</span>
                        <span class="text-2xl font-black">{{ Money::rub($subtotal) }}</span>
                    </div>

                    {{-- Оформление подключается вместе с оплатой и доставкой: они ждут
                         письма бухгалтера по НДС, ответа банка и габаритов SKU. --}}
                    <button type="button" disabled
                            class="btn btn-neon mt-6 w-full justify-center opacity-40 cursor-not-allowed">
                        Перейти к оформлению
                    </button>
                    <p class="mt-3 text-[10px] leading-relaxed text-muted">
                        Оформление заказа появится вместе с оплатой и доставкой — следующий этап работ.
                    </p>
                </aside>
            </div>

            {{-- Выбор подарка: механика от 5 000 ₽, порог меняется из админки --}}
            <div class="mt-14 border border-line p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-4">
                    <h2 class="text-2xl">Подарок к заказу</h2>
                    @if (! $giftUnlocked && $remainingToGift)
                        <span class="text-[11px] uppercase tracking-[0.08em] text-muted">
                            Осталось добрать {{ Money::rub($remainingToGift) }}
                        </span>
                    @endif
                </div>

                @if ($eligibleGifts->isEmpty())
                    <p class="mt-4 text-xs text-muted">Сейчас подарки не настроены.</p>
                @elseif (! $giftUnlocked)
                    <p class="mt-4 text-xs text-muted">
                        Выбор подарка откроется, когда сумма заказа достигнет порога.
                    </p>
                @else
                    <p class="mt-4 text-xs text-muted">Выберите один подарок — он добавится к заказу бесплатно.</p>

                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        @foreach ($eligibleGifts as $gift)
                            @php $chosen = $cart->giftItem()?->product_variant_id === $gift->id; @endphp
                            <form method="POST" action="{{ route('cart.gift') }}">
                                @csrf
                                <input type="hidden" name="variant_id" value="{{ $gift->id }}">
                                <button type="submit" @class([
                                    'w-full border p-4 text-left transition',
                                    'border-ink bg-shell' => $chosen,
                                    'border-line hover:border-ink' => ! $chosen,
                                ])>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                        {{ $gift->product->line?->title }}
                                    </div>
                                    <div class="mt-1 text-xs font-bold">{{ $gift->product->title }}</div>
                                    <div class="mt-1 text-[11px] text-muted">{{ $gift->storefrontLabel() }}</div>
                                    <div class="mt-3 text-[10px] uppercase tracking-[0.08em]">
                                        {{ $chosen ? 'Выбран' : 'Выбрать' }}
                                    </div>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </section>
@endsection