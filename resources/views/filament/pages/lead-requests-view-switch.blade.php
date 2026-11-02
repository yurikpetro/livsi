<div style="display: flex; justify-content: flex-end; gap: .5rem" role="group" aria-label="Вид заявок">
    <x-filament::button
        wire:click="setDisplayMode('table')"
        :color="$this->displayMode === 'table' ? 'primary' : 'gray'"
        :aria-pressed="$this->displayMode === 'table' ? 'true' : 'false'"
        icon="heroicon-o-table-cells"
        size="sm"
    >
        Таблица
    </x-filament::button>
    <x-filament::button
        wire:click="setDisplayMode('kanban')"
        :color="$this->displayMode === 'kanban' ? 'primary' : 'gray'"
        :aria-pressed="$this->displayMode === 'kanban' ? 'true' : 'false'"
        icon="heroicon-o-view-columns"
        size="sm"
    >
        Канбан
    </x-filament::button>
</div>
