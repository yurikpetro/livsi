<header class="orders-header">
    <style>
        .orders-header { display: grid; gap: .75rem; min-width: 0; }
        .orders-header-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 1rem; min-width: 0; }
        .orders-header-tabs { grid-column: 1 / -1; grid-row: 2; min-width: 0; }
        .orders-view-switch { display: flex; flex-wrap: wrap; gap: .5rem; grid-column: 2; grid-row: 1; justify-self: end; }
        @media (min-width: 1320px) {
            .orders-header-row { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); }
            .orders-header-tabs { grid-column: 2; grid-row: 1; }
            .orders-view-switch { grid-column: 3; }
        }
        @media (max-width: 374px) {
            .orders-view-switch { grid-column: 1 / -1; grid-row: 2; justify-self: start; }
            .orders-header-tabs { grid-row: 3; }
        }
    </style>
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />
    <div class="orders-header-row">
        <h1 class="fi-header-heading">{{ $this->getHeading() }}</h1>
        <div class="orders-header-tabs">{{ $this->headerTabs }}</div>
        <div class="orders-view-switch" role="group" aria-label="Вид заказов">
            @foreach (['table' => ['Таблица', 'heroicon-o-table-cells'], 'kanban' => ['Канбан', 'heroicon-o-view-columns']] as $mode => [$label, $icon])
                <x-filament::button
                    wire:click="setDisplayMode('{{ $mode }}')"
                    :color="$this->displayMode === $mode ? 'primary' : 'gray'"
                    :aria-pressed="$this->displayMode === $mode ? 'true' : 'false'"
                    :icon="$icon"
                    size="sm"
                >{{ $label }}</x-filament::button>
            @endforeach
        </div>
    </div>
</header>
