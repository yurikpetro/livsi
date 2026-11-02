<header class="lead-requests-header">
    <style>
        .lead-requests-header { display: grid; gap: .5rem; }
        .lead-requests-header-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 1rem; min-width: 0; }
        .lead-requests-header-tabs { grid-column: 1 / -1; grid-row: 2; min-width: 0; width: 100%; }
        .lead-requests-header-switch { grid-column: 2; grid-row: 1; justify-self: end; }
        @media (min-width: 1320px) {
            .lead-requests-header-row { grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); }
            .lead-requests-header-tabs { grid-column: 2; grid-row: 1; width: auto; }
            .lead-requests-header-switch { grid-column: 3; }
        }
        @media (max-width: 374px) {
            .lead-requests-header-switch { grid-column: 1 / -1; grid-row: 2; }
            .lead-requests-header-tabs { grid-row: 3; }
        }
    </style>
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />
    <div class="lead-requests-header-row">
        <h1 class="fi-header-heading">{{ $this->getHeading() }}</h1>
        <div class="lead-requests-header-tabs">
            {{ $this->headerTabs }}
        </div>
        <div class="lead-requests-header-switch">
            @include('filament.pages.lead-requests-view-switch')
        </div>
    </div>
</header>
