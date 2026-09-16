@php use App\Support\Money; @endphp

<div>
    {{-- Кнопка в шапке со счётчиком --}}
    <button type="button" wire:click="openDrawer"
            class="relative flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.08em] hover:text-green"
            aria-label="Корзина: {{ $count }}">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M4 7h16l-1.3 11.2a2 2 0 0 1-2 1.8H7.3a2 2 0 0 1-2-1.8L4 7Z"></path>
            <path d="M9 7V5.5a3 3 0 0 1 6 0V7"></path>
        </svg>
        <span class="hidden sm:inline">Корзина</span>
        @if ($count > 0)
            <span class="grid h-4 min-w-4 place-items-center rounded-full bg-neon px-1 text-[10px] font-bold text-ink">{{ $count }}</span>
        @endif
    </button>

    {{-- Панель выносим в body: у шапки backdrop-blur, а он создаёт
         содержащий блок для position: fixed и ломает оверлей. --}}
    @teleport('body')
        <div x-data
             x-show="$wire.open"
             x-cloak
             @keydown.escape.window="$wire.closeDrawer()"
             class="fixed inset-0 z-50"
             role="dialog"
             aria-modal="true"
             aria-label="Корзина">

            <div x-show="$wire.open"
                 x-transition:enter="transition ease-out duration-250"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 wire:click="closeDrawer"
                 class="absolute inset-0 bg-ink/40"></div>

            <aside x-show="$wire.open"
                   x-transition:enter="transition duration-400 ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                   x-transition:enter-start="translate-x-full"
                   x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition duration-300 ease-[cubic-bezier(0.4,0,1,1)]"
                   x-transition:leave-start="translate-x-0"
                   x-transition:leave-end="translate-x-full"
                   class="absolute right-0 top-0 flex h-full w-full flex-col bg-paper shadow-2xl sm:w-[460px]">

                {{-- Шапка панели --}}
                <div class="flex items-center justify-between border-b border-line px-6 py-5">
                    <span class="text-[11px] font-bold uppercase tracking-[0.1em]">
                        Корзина @if ($count > 0) · {{ $count }} @endif
                    </span>
                    <button type="button" wire:click="closeDrawer" class="text-muted hover:text-ink" aria-label="Закрыть корзину">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="m6 6 12 12M18 6 6 18"></path>
                        </svg>
                    </button>
                </div>

                @if ($cart->isEmpty())
                    <div class="flex flex-1 flex-col items-center justify-center px-6 text-center">
                        <p class="text-lg font-bold">Пока пусто</p>
                        <p class="mt-2 text-xs text-muted">Загляните в каталог — начните с бестселлеров.</p>
                        <a href="{{ route('catalog.index') }}" class="btn btn-dark mt-6">
                            В каталог <span aria-hidden="true">→</span>
                        </a>
                    </div>
                @else
                    {{-- Прогресс до бесплатной доставки --}}
                    <div class="border-b border-line px-6 py-4">
                        <div class="flex items-center justify-between text-[10px] font-bold uppercase tracking-[0.08em]">
                            <span>
                                @if ($remaining > 0)
                                    До бесплатной доставки — {{ Money::rub($remaining) }}
                                @else
                                    Доставка бесплатно
                                @endif
                            </span>
                            <span class="text-muted">{{ $progress }}%</span>
                        </div>
                        <div class="mt-2.5 h-0.5 w-full bg-shell">
                            <div class="h-0.5 bg-neon transition-all duration-300" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>

                    {{-- Позиции --}}
                    <div class="flex-1 overflow-y-auto px-6">
                        @foreach ($cart->items as $item)
                            @php $image = $item->variant->product->primaryImage(); @endphp

                            <div wire:key="cart-item-{{ $item->id }}" class="flex gap-4 border-b border-line py-5 last:border-b-0">
                                <a href="{{ route('catalog.show', $item->variant->product) }}"
                                   wire:click="closeDrawer"
                                   class="block w-16 shrink-0 bg-shell">
                                    @if ($image)
                                        <x-img :path="$image->path" :alt="$image->alt" sizes="64px"
                                               class="aspect-[4/5] w-full object-cover" />
                                    @endif
                                </a>

                                <div class="flex flex-1 flex-col">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                            {{ $item->variant->product->line?->title }}
                                            @if ($item->is_gift)
                                                <span class="ml-1 bg-neon px-1.5 py-0.5 text-ink">Подарок</span>
                                            @endif
                                        </span>

                                        <span class="whitespace-nowrap text-right text-xs">
                                            @if ($item->is_gift)
                                                <span class="text-muted line-through">{{ Money::rub($item->unitPrice()) }}</span>
                                                <span class="ml-1 font-bold">0 ₽</span>
                                            @else
                                                <span class="font-bold">{{ Money::rub($item->lineTotal()) }}</span>
                                            @endif
                                        </span>
                                    </div>

                                    <a href="{{ route('catalog.show', $item->variant->product) }}"
                                       wire:click="closeDrawer"
                                       class="mt-1 text-xs font-bold leading-snug hover:text-green">
                                        {{ $item->variant->product->title }}
                                    </a>

                                    <div class="mt-0.5 text-[11px] text-muted">{{ $item->variant->storefrontLabel() }}</div>

                                    @unless ($item->is_gift)
                                        <div class="mt-3 flex items-center justify-between">
                                            <div class="flex items-center border border-line">
                                                <button type="button" wire:click="decrement({{ $item->id }})"
                                                        class="h-7 w-7 text-sm hover:bg-shell" aria-label="Уменьшить количество">−</button>
                                                <span class="w-7 text-center text-xs">{{ $item->qty }}</span>
                                                <button type="button" wire:click="increment({{ $item->id }})"
                                                        @disabled($item->variant->available() <= $item->qty)
                                                        class="h-7 w-7 text-sm hover:bg-shell disabled:opacity-30 disabled:cursor-not-allowed"
                                                        aria-label="Увеличить количество">+</button>
                                            </div>

                                            <button type="button" wire:click="removeItem({{ $item->id }})"
                                                    class="text-[10px] uppercase tracking-[0.08em] text-muted underline hover:text-danger">
                                                Удалить
                                            </button>
                                        </div>
                                    @endunless
                                </div>
                            </div>
                        @endforeach

                        {{-- Выбор подарка. В макете этого блока нет, но механика
                             «подарок от порога» согласована — показываем компактно. --}}
                        @if ($gifts->isNotEmpty())
                            <div class="border-t border-line py-5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.1em]">Подарок к заказу</span>
                                    @unless ($giftUnlocked)
                                        <span class="text-[10px] text-muted">добрать {{ Money::rub($remainingGift) }}</span>
                                    @endunless
                                </div>

                                @if ($giftUnlocked)
                                    <div class="mt-3 space-y-2">
                                        @foreach ($gifts as $gift)
                                            @php $chosen = $cart->giftItem()?->product_variant_id === $gift->id; @endphp
                                            <button type="button" wire:click="chooseGift({{ $gift->id }})"
                                                    wire:key="gift-{{ $gift->id }}"
                                                    @class([
                                                        'flex w-full items-center justify-between border px-3 py-2.5 text-left transition',
                                                        'border-ink bg-shell' => $chosen,
                                                        'border-line hover:border-ink' => ! $chosen,
                                                    ])>
                                                <span>
                                                    <span class="block text-[11px] font-bold">{{ $gift->product->title }}</span>
                                                    <span class="block text-[10px] text-muted">{{ $gift->storefrontLabel() }}</span>
                                                </span>
                                                <span class="text-[9px] uppercase tracking-[0.08em] {{ $chosen ? 'text-ink' : 'text-muted' }}">
                                                    {{ $chosen ? 'Выбран' : 'Выбрать' }}
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="mt-2 text-[11px] leading-relaxed text-muted">
                                        Выбор подарка откроется при сумме заказа от {{ Money::rub($subtotal + $remainingGift) }}.
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Подвал панели --}}
                    <div class="border-t border-line px-6 py-5">
                        <div class="flex items-baseline justify-between">
                            <span class="text-[11px] font-bold uppercase tracking-[0.1em]">Итого</span>
                            <span class="text-2xl font-black">{{ Money::rub($subtotal) }}</span>
                        </div>

                        <p class="mt-2 text-[10px] leading-relaxed text-muted">
                            Стоимость и срок доставки будут рассчитаны при оформлении.
                        </p>

                        <a href="{{ route('checkout.index') }}" class="btn btn-neon mt-4 w-full justify-center">
                            Перейти к оформлению <span aria-hidden="true">→</span>
                        </a>
                    </div>
                @endif
            </aside>
        </div>
    @endteleport
</div>
