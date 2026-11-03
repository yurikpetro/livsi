<div class="orders-kanban" aria-label="Заказы по статусам доставки">
    <style>
        .orders-kanban { min-width: 0; }
        .orders-kanban-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem; margin-bottom: 1rem; }
        .orders-kanban-search { flex: 1 1 16rem; }
        .orders-kanban-filters { flex: 1 1 16rem; }
        .orders-kanban-label { display: block; margin-bottom: .5rem; font-size: .875rem; font-weight: 500; }
        .orders-kanban-board { display: grid; grid-template-columns: repeat(6, minmax(15rem, 1fr)); gap: 1rem; overflow-x: auto; padding-bottom: 1rem; align-items: start; }
        .orders-kanban-column { min-width: 0; border: 1px solid #e5e7eb; border-radius: .75rem; background: #f9fafb; }
        .orders-kanban-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: 1rem; font-size: .875rem; font-weight: 600; }
        .orders-kanban-totals { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }
        .orders-kanban-amount { font-size: .75rem; font-weight: 500; white-space: nowrap; }
        .orders-kanban-cards { display: grid; gap: .75rem; padding: 0 .75rem .75rem; min-height: 7rem; align-content: start; }
        .orders-kanban-card { display: grid; gap: .75rem; padding: 1rem; border: 1px solid #e5e7eb; border-radius: .5rem; background: #fff; font-size: .875rem; overflow-wrap: anywhere; }
        .orders-kanban-card-header { display: flex; justify-content: space-between; align-items: start; gap: .5rem; }
        .orders-kanban-drag-handle { cursor: grab; touch-action: none; flex-shrink: 0; padding: .25rem; border-radius: .25rem; }
        .orders-kanban-drag-handle:active { cursor: grabbing; }
        .orders-kanban-drag-handle:focus-visible { outline: 2px solid #51a940; outline-offset: 2px; }
        .orders-kanban-card.sortable-ghost { opacity: .3; border-color: #51a940; }
        .orders-kanban-hint { margin-bottom: .75rem; color: #6b7280; font-size: .75rem; }
        .orders-kanban-meta { color: #6b7280; font-size: .75rem; }
        .orders-kanban-name { font-weight: 600; }
        .orders-kanban-empty { padding: 1.5rem .5rem; text-align: center; color: #6b7280; font-size: .875rem; }
        .dark .orders-kanban-column { background: #ffffff05; border-color: #ffffff1a; }
        .dark .orders-kanban-card { background: #18181b; border-color: #ffffff1a; }
        .dark .orders-kanban-meta, .dark .orders-kanban-empty, .dark .orders-kanban-hint { color: #a1a1aa; }
    </style>
    <div class="orders-kanban-toolbar">
        <div class="orders-kanban-search">
            <label class="orders-kanban-label" for="orders-kanban-search">Поиск заказа или покупателя</label>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input id="orders-kanban-search" type="search" wire:model.live.debounce.300ms="tableSearch" placeholder="Номер, имя или почта" />
            </x-filament::input.wrapper>
        </div>
        <div class="orders-kanban-filters">{{ $this->tableFiltersForm }}</div>
    </div>
    <p class="orders-kanban-hint">Перетаскивайте карточки за значок справа или используйте меню «Переместить». На доске — оплаченные заказы и заказы с возвратом оплаты, имеющие статус доставки.</p>
    <div class="orders-kanban-board" tabindex="0" aria-label="Колонки доставки, прокручиваются по горизонтали">
        @foreach ($this->getKanbanColumns() as $status => $column)
            <section class="orders-kanban-column" wire:key="orders-column-{{ $status }}" aria-labelledby="orders-heading-{{ $status }}">
                <h2 class="orders-kanban-heading" id="orders-heading-{{ $status }}">
                    <span>{{ $column['label'] }}</span>
                    <div class="orders-kanban-totals">
                        <span class="orders-kanban-amount" aria-label="Сумма заказов: {{ $column['label'] }}">{{ \App\Support\Money::rub($column['amount']) }}</span>
                        <x-filament::badge :color="$column['color']">{{ $column['total'] }}</x-filament::badge>
                    </div>
                </h2>
                <div class="orders-kanban-cards"
                    wire:sort.ghost="moveSortedKanbanRecord"
                    wire:sort:group="orders-delivery"
                    wire:sort:group-id="{{ $status }}"
                    wire:sort:config="{ sort: false, handle: '.orders-kanban-drag-handle', delay: 150, delayOnTouchOnly: true }"
                >
                    @forelse ($column['records'] as $record)
                        @php($canMove = \App\Filament\Resources\Orders\OrderResource::canEdit($record) && \App\Support\Fulfillment::canChange($record))
                        <article class="orders-kanban-card" wire:key="orders-card-{{ $record->getKey() }}" wire:sort:item="{{ $record->getKey() }}">
                            <div class="orders-kanban-card-header">
                                <div>
                                    <x-filament::link :href="$this->getResourceUrl('edit', ['record' => $record])" :aria-label="'Открыть заказ ' . $record->number">{{ $record->number }}</x-filament::link>
                                    <div class="orders-kanban-meta"><time datetime="{{ $record->created_at->toIso8601String() }}">{{ \App\Support\Fulfillment::moscow($record->created_at, 'd.m.Y H:i') }}</time></div>
                                </div>
                                @if ($canMove)
                                    <button type="button" class="orders-kanban-drag-handle" wire:sort:handle aria-label="Перетащить заказ {{ $record->number }}" title="Перетащить в другую колонку" tabindex="-1">
                                        <x-filament::icon icon="heroicon-o-arrows-pointing-out" style="width: 1rem; height: 1rem" />
                                    </button>
                                @endif
                            </div>
                            <div>
                                <h3 class="orders-kanban-name">{{ $record->customer_name }}</h3>
                                <p class="orders-kanban-meta">{{ $record->customer_phone }}</p>
                            </div>
                            <div class="orders-kanban-card-header">
                                <strong>{{ \App\Support\Money::rub($record->total) }}</strong>
                                <x-filament::badge :color="$record->isPaid() ? 'success' : 'gray'">{{ $record->statusLabel() }}</x-filament::badge>
                                @if ($record->outcome())<p class="orders-kanban-meta">{{ $record->outcome() }}</p>@endif
                                @if ($record->promo_code)<p class="orders-kanban-meta">Промокод {{ $record->promo_code }}</p>@endif
                            </div>
                            <div class="orders-kanban-meta">
                                <p>{{ \App\Services\DeliverySelection::label($record->delivery_method) }}</p>
                                @if ($record->delivery_city)<p>{{ $record->delivery_city }}</p>@endif
                                <p>{{ $record->shipmentLabel() }}</p>
                                @if ($record->shipmentReference())<p>{{ $record->shipmentReference() }}</p>@endif
                                @if ($record->shipment_tracking_number)<p>Трек: {{ $record->shipment_tracking_number }}</p>@endif
                            </div>
                            @if ($canMove)
                                <x-filament::dropdown placement="bottom-start" teleport>
                                    <x-slot name="trigger">
                                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrows-right-left" :aria-label="'Переместить заказ ' . $record->number">Переместить</x-filament::button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        @foreach (\App\Support\Fulfillment::options($record) as $targetStatus => $targetLabel)
                                            @if ($targetStatus !== $status)
                                                <x-filament::dropdown.list.item wire:click="moveKanbanRecord({{ $record->getKey() }}, '{{ $targetStatus }}')" wire:loading.attr="disabled" wire:target="moveKanbanRecord,moveSortedKanbanRecord">{{ $targetLabel }}</x-filament::dropdown.list.item>
                                            @endif
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            @endif
                        </article>
                    @empty
                        <p class="orders-kanban-empty">Нет заказов</p>
                    @endforelse
                    @if ($column['total'] > $column['records']->count())
                        <x-filament::button wire:click="loadMoreKanbanRecords('{{ $status }}')" color="gray" size="sm">Показать ещё</x-filament::button>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
