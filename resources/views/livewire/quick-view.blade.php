@php
    use App\Support\Money;
    use App\Support\Plural;

    $variant = $product?->variants->firstWhere('id', $variantId) ?? $product?->defaultVariant();
    $image   = $product?->primaryImage();
@endphp

<div>
    @teleport('body')
        <div x-data
             x-show="$wire.open"
             x-cloak
             x-on:keydown.escape.window="$wire.close()"
             class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-6"
             role="dialog"
             aria-modal="true"
             aria-label="Быстрый просмотр товара">

            <div x-show="$wire.open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 wire:click="close"
                 class="absolute inset-0 bg-ink/50"></div>

            @if ($product)
                <div x-show="$wire.open"
                     x-transition:enter="transition duration-[380ms] ease-[cubic-bezier(0.2,0.8,0.2,1)]"
                     x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-6 sm:scale-[0.98]"
                     x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
                     class="relative max-h-[92vh] w-full max-w-3xl overflow-y-auto bg-paper shadow-2xl">

                    <button type="button" wire:click="close"
                            class="absolute right-4 top-4 z-10 bg-paper/90 p-1 text-muted hover:text-ink"
                            aria-label="Закрыть быстрый просмотр">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path d="m6 6 12 12M18 6 6 18"></path>
                        </svg>
                    </button>

                    <div class="grid sm:grid-cols-2">
                        <div class="bg-shell">
                            @if ($image)
                                <x-img :path="$image->path" :alt="$image->alt"
                                       sizes="(min-width: 640px) 384px, 100vw"
                                       class="aspect-[4/5] w-full object-cover" />
                            @endif
                        </div>

                        <div class="flex flex-col p-6 md:p-8">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                    {{ $product->line?->title }}@if ($product->is_pro) PRO @endif
                                </span>
                                @if ($product->rating)
                                    {{-- Источник рейтинга указывается и здесь: отзывы собраны не на сайте. --}}
                                    <span class="text-[11px] text-muted">
                                        ★ {{ number_format($product->rating, 1, ',', '') }} ·
                                        {{ Plural::reviews((int) $product->reviews_count) }} на {{ $product->reviews_source }}
                                    </span>
                                @endif
                            </div>

                            <h2 class="mt-3 text-2xl normal-case tracking-tight md:text-3xl">{{ $product->title }}</h2>
                            <p class="mt-2 text-xs leading-relaxed text-muted">{{ $product->short_description }}</p>

                            @if ($product->variants->count() > 1)
                                <div class="mt-5">
                                    <div class="eyebrow">Вариант</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($product->variants as $v)
                                            @php $out = $v->available() < 1; @endphp
                                            <button type="button"
                                                    wire:click="selectVariant({{ $v->id }})"
                                                    wire:key="qv-variant-{{ $v->id }}"
                                                    @disabled($out)
                                                    @class([
                                                        'chip',
                                                        'chip-active' => $variant?->id === $v->id,
                                                        'opacity-40 line-through cursor-not-allowed' => $out,
                                                    ])>
                                                {{ $v->storefrontLabel() }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif ($variant)
                                <p class="mt-4 text-[11px] text-muted">{{ $variant->storefrontLabel() }}</p>
                            @endif

                            <dl class="mt-5 divide-y divide-line border-y border-line text-xs">
                                @foreach ([
                                    'Аромат'     => $product->aroma,
                                    'Назначение' => $product->purposes->pluck('title')->implode(', ') ?: null,
                                    'Задача'     => $product->tasks->pluck('title')->implode(', ') ?: null,
                                ] as $label => $value)
                                    @if ($value)
                                        <div class="flex justify-between gap-4 py-2.5">
                                            <dt class="text-[10px] uppercase tracking-[0.08em] text-muted">{{ $label }}</dt>
                                            <dd class="text-right font-bold">{{ $value }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>

                            <div class="mt-auto pt-6">
                                <div class="flex items-center gap-4">
                                    <span class="text-2xl font-black">{{ Money::rub($variant?->price) }}</span>
                                    <button type="button" wire:click="addToCart"
                                            @disabled(! $product->isAvailable() || ! $variantId)
                                            class="btn btn-neon flex-1 disabled:cursor-not-allowed disabled:opacity-40">
                                        {{ $product->isAvailable() ? 'В корзину' : 'Нет в наличии' }}
                                        <span aria-hidden="true">→</span>
                                    </button>
                                </div>

                                {{-- Ссылка на полную карточку обязательна: быстрый просмотр
                                     не заменяет страницу товара с составом и документами. --}}
                                <a href="{{ route('catalog.show', $product) }}"
                                   class="mt-4 inline-block text-[10px] uppercase tracking-[0.08em] text-muted underline hover:text-ink">
                                    Открыть полную карточку
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endteleport
</div>
