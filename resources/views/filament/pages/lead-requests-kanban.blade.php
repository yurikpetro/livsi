<div class="lead-kanban" aria-label="Заявки по статусам">
    <style>
        .lead-kanban { min-width: 0; }
        .lead-kanban-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 1rem; margin-bottom: 1.25rem; }
        .lead-kanban-search { flex: 1 1 16rem; }
        .lead-kanban-filters { flex: 1 1 22rem; }
        .lead-kanban-label { display: block; margin-bottom: .5rem; font-size: .875rem; font-weight: 500; }
        .lead-kanban-board { display: grid; grid-template-columns: repeat(4, minmax(15rem, 1fr)); gap: 1rem; overflow-x: auto; padding-bottom: 1rem; align-items: start; }
        .lead-kanban-column { min-width: 0; border: 1px solid #e5e7eb; border-radius: .75rem; background: #f9fafb; }
        .lead-kanban-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: 1rem; font-size: .875rem; font-weight: 600; }
        .lead-kanban-cards { display: grid; gap: .75rem; padding: 0 .75rem .75rem; min-height: 7rem; align-content: start; }
        .lead-kanban-card-header { display: flex; justify-content: space-between; align-items: start; gap: .5rem; }
        .lead-kanban-drag-handle { cursor: grab; touch-action: none; flex-shrink: 0; padding: .25rem; border-radius: .25rem; }
        .lead-kanban-drag-handle:active { cursor: grabbing; }
        .lead-kanban-drag-handle:focus-visible { outline: 2px solid #51a940; outline-offset: 2px; }
        .lead-kanban-card.sortable-ghost { opacity: .3; border-color: #51a940; }
        .lead-kanban-hint { margin-bottom: .75rem; color: #6b7280; font-size: .75rem; }
        .dark .lead-kanban-hint { color: #a1a1aa; }
        .lead-kanban-card { display: grid; gap: .75rem; padding: 1rem; border: 1px solid #e5e7eb; border-radius: .5rem; background: #fff; font-size: .875rem; overflow-wrap: anywhere; }
        .lead-kanban-meta { color: #6b7280; font-size: .75rem; }
        .lead-kanban-name { font-weight: 600; }
        .lead-kanban-empty { padding: 1.5rem .5rem; text-align: center; color: #6b7280; font-size: .875rem; }
        .dark .lead-kanban-column { background: #ffffff05; border-color: #ffffff1a; }
        .dark .lead-kanban-card { background: #18181b; border-color: #ffffff1a; }
        .dark .lead-kanban-meta, .dark .lead-kanban-empty { color: #a1a1aa; }
    </style>

    <div class="lead-kanban-toolbar">
        <div class="lead-kanban-search">
            <label class="lead-kanban-label" for="lead-kanban-search">Поиск по имени или контакту</label>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input id="lead-kanban-search" type="search" wire:model.live.debounce.300ms="tableSearch" placeholder="Имя, телефон или Telegram" />
            </x-filament::input.wrapper>
        </div>
        <div class="lead-kanban-filters">
            {{ $this->tableFiltersForm }}
        </div>
    </div>

    <p class="lead-kanban-hint">Перетаскивайте карточки за значок справа в другую колонку или используйте меню «Переместить».</p>
    <div class="lead-kanban-board" tabindex="0" aria-label="Колонки статусов, прокручиваются по горизонтали">
        @foreach ($this->getKanbanColumns() as $status => $column)
            <section class="lead-kanban-column" wire:key="kanban-column-{{ $status }}" aria-labelledby="kanban-heading-{{ $status }}">
                <h2 class="lead-kanban-heading" id="kanban-heading-{{ $status }}">
                    <span>{{ $column['label'] }}</span>
                    <x-filament::badge :color="$column['color']">{{ $column['total'] }}</x-filament::badge>
                </h2>
                <div
                    class="lead-kanban-cards"
                    wire:sort.ghost="moveSortedKanbanRecord"
                    wire:sort:group="lead-requests"
                    wire:sort:group-id="{{ $status }}"
                    wire:sort:config="{ sort: false, handle: '.lead-kanban-drag-handle', delay: 150, delayOnTouchOnly: true }"
                >
                    @forelse ($column['records'] as $record)
                        <article class="lead-kanban-card" wire:key="kanban-card-{{ $record->getKey() }}" wire:sort:item="{{ $record->getKey() }}">
                            <div class="lead-kanban-card-header">
                                <div class="lead-kanban-meta">
                                    <time datetime="{{ $record->created_at->toIso8601String() }}">{{ $record->created_at->format('d.m.Y H:i') }}</time>
                                    <div>{{ $record->typeLabel() }}</div>
                                </div>
                                @if (\App\Filament\Resources\LeadRequests\LeadRequestResource::canEdit($record))
                                    <button type="button" class="lead-kanban-drag-handle" wire:sort:handle aria-label="Перетащить заявку: {{ $record->name }}" title="Перетащить в другую колонку" tabindex="-1">
                                        <x-filament::icon icon="heroicon-o-arrows-pointing-out" style="width: 1rem; height: 1rem" />
                                    </button>
                                @endif
                            </div>
                            <div>
                                <h3 class="lead-kanban-name">{{ $record->name }}</h3>
                                <p>{{ $record->contact }}</p>
                            </div>
                            <p>{{ $record->type === \App\Models\LeadRequest::TYPE_CONTRACT
                                ? ($record->categoryLabel() ?? 'Категория не указана') . ' · ' . ($record->planned_volume ?? 'объём не указан')
                                : ($record->city ?? 'Город не указан') . ' · ' . ($record->salesFormatLabel() ?? 'формат не указан') }}</p>
                            @if (\App\Filament\Resources\LeadRequests\LeadRequestResource::canEdit($record))
                                <x-filament::link :href="$this->getResourceUrl('edit', ['record' => $record])" icon="heroicon-o-pencil-square" :aria-label="'Открыть заявку: ' . $record->name">Открыть заявку</x-filament::link>
                                <x-filament::dropdown placement="bottom-start" teleport>
                                    <x-slot name="trigger">
                                        <x-filament::button size="xs" color="gray" icon="heroicon-o-arrows-right-left" :aria-label="'Переместить заявку: ' . $record->name">Переместить</x-filament::button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        @foreach (\App\Models\LeadRequest::statuses() as $targetStatus => $targetLabel)
                                            @if ($targetStatus !== $status)
                                                <x-filament::dropdown.list.item wire:click="moveKanbanRecord({{ $record->getKey() }}, '{{ $targetStatus }}')" wire:loading.attr="disabled" wire:target="moveKanbanRecord">{{ $targetLabel }}</x-filament::dropdown.list.item>
                                            @endif
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            @endif
                        </article>
                    @empty
                        <p class="lead-kanban-empty">Нет заявок</p>
                    @endforelse
                    @if ($column['total'] > $column['records']->count())
                        <x-filament::button wire:click="loadMoreKanbanRecords('{{ $status }}')" color="gray" size="sm">Показать ещё</x-filament::button>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
