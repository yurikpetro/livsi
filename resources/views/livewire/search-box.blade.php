@php use App\Support\Money; @endphp

<div>
    <button type="button" wire:click="openSearch"
            class="text-ink/70 hover:text-ink" aria-label="Поиск">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
        </svg>
    </button>

    {{-- Оверлей поиска выносим в body по той же причине, что и корзину:
         backdrop-blur у шапки создаёт содержащий блок для position: fixed. --}}
    @teleport('body')
        <div x-data
             x-show="$wire.open"
             x-cloak
             @keydown.escape.window="$wire.closeSearch()"
             class="fixed inset-0 z-50"
             role="dialog"
             aria-modal="true"
             aria-label="Поиск по каталогу">

            <div x-show="$wire.open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 wire:click="closeSearch"
                 class="absolute inset-0 bg-ink/40"></div>

            <div x-show="$wire.open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="-translate-y-4 opacity-0"
                 x-transition:enter-end="translate-y-0 opacity-100"
                 class="absolute inset-x-0 top-0 bg-paper shadow-2xl">

                <div class="site-container py-6">
                    <form action="{{ route('catalog.index') }}" method="GET" class="flex items-center gap-4">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                             class="shrink-0 text-muted" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                        </svg>

                        {{-- Форма отправляется обычным GET и работает без JavaScript;
                             с Livewire по мере ввода появляются подсказки. --}}
                        <input type="search" name="q" wire:model.live.debounce.300ms="q"
                               x-init="$watch('$wire.open', v => v && setTimeout(() => $el.focus(), 60))"
                               placeholder="Пенка, скраб, артикул, «для стоп»…"
                               autocomplete="off"
                               class="w-full border-0 bg-transparent py-2 text-lg outline-none placeholder:text-muted/60">

                        <button type="button" wire:click="closeSearch" class="shrink-0 text-muted hover:text-ink"
                                aria-label="Закрыть поиск">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18"></path>
                            </svg>
                        </button>
                    </form>

                    @if (mb_strlen(trim($q)) >= 2)
                        <div class="mt-5 border-t border-line pt-5">
                            @if ($suggestions->isEmpty())
                                <p class="text-xs text-muted">
                                    По запросу «{{ trim($q) }}» ничего не нашлось. Попробуйте короче или другими словами.
                                </p>
                            @else
                                <div class="grid gap-1">
                                    @foreach ($suggestions as $product)
                                        @php $image = $product->primaryImage(); @endphp
                                        <a href="{{ route('catalog.show', $product) }}"
                                           wire:key="hit-{{ $product->id }}"
                                           class="flex items-center gap-4 px-2 py-2 hover:bg-shell">
                                            <span class="block w-10 shrink-0 bg-shell">
                                                @if ($image)
                                                    <x-img :path="$image->path" alt="" sizes="40px"
                                                           class="aspect-[4/5] w-full object-cover" />
                                                @endif
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-[10px] font-bold uppercase tracking-[0.1em] text-muted">
                                                    {{ $product->line?->title ?? ($product->is_pro ? 'PRO' : '') }}
                                                </span>
                                                <span class="block truncate text-xs font-bold">{{ $product->title }}</span>
                                            </span>
                                            <span class="shrink-0 text-xs font-bold">{{ Money::rub($product->priceFrom()) }}</span>
                                        </a>
                                    @endforeach
                                </div>

                                <a href="{{ $resultsUrl }}" class="btn btn-dark mt-5">
                                    Показать все результаты <span aria-hidden="true">→</span>
                                </a>
                            @endif
                        </div>
                    @else
                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-line pt-5">
                            <span class="eyebrow">Часто ищут</span>
                            @foreach (['пенка', 'скраб', 'для стоп', 'urea', 'мист'] as $hint)
                                <button type="button" wire:click="$set('q', '{{ $hint }}')" class="chip">{{ $hint }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endteleport
</div>
