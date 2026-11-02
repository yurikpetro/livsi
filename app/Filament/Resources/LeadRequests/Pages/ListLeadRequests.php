<?php

namespace App\Filament\Resources\LeadRequests\Pages;

use App\Filament\Resources\LeadRequests\LeadRequestResource;
use App\Models\LeadRequest;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ListLeadRequests extends ListRecords
{
    protected static string $resource = LeadRequestResource::class;

    #[Url(as: 'view')]
    public string $displayMode = 'table';

    /** @var array<string, int> */
    #[Locked]
    public array $kanbanLimits = [];

    public function mount(): void
    {
        if (! request()->has('view')) {
            $this->displayMode = session('lead-requests.layout', 'table');
        }

        if (! in_array($this->displayMode, ['table', 'kanban'], true)) {
            $this->displayMode = 'table';
        }

        parent::mount();
    }

    public function setDisplayMode(string $displayMode): void
    {
        abort_unless(in_array($displayMode, ['table', 'kanban'], true), 422);

        $this->displayMode = $displayMode;
        session()->put('lead-requests.layout', $displayMode);

        if (($displayMode === 'kanban') && ($this->activeTab === 'new')) {
            $this->activeTab = 'all';
            $this->updatedActiveTab();
        }
    }

    public function getDefaultActiveTab(): string
    {
        return $this->displayMode === 'kanban' ? 'all' : 'new';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make()->visible(fn (): bool => $this->displayMode !== 'kanban'),
            View::make('filament.pages.lead-requests-kanban')
                ->visible(fn (): bool => $this->displayMode === 'kanban'),
        ]);
    }

    public function getHeader(): ?ViewContract
    {
        return view('filament.pages.lead-requests-header');
    }

    public function headerTabs(Schema $schema): Schema
    {
        return $schema->components([$this->getTabsContentComponent()]);
    }

    /** @return array<string, array{label: string, color: string, records: Collection<int, LeadRequest>, total: int}> */
    public function getKanbanColumns(): array
    {
        $columns = [];

        foreach (LeadRequest::statuses() as $status => $label) {
            $query = (clone $this->getFilteredTableQuery())->where('status', $status);

            $columns[$status] = [
                'label' => $label,
                'color' => match ($status) {
                    LeadRequest::STATUS_NEW => 'warning',
                    LeadRequest::STATUS_IN_PROGRESS => 'info',
                    LeadRequest::STATUS_DONE => 'success',
                    LeadRequest::STATUS_REJECTED => 'danger',
                },
                'total' => (clone $query)->count(),
                'records' => $query->reorder()->orderByDesc('created_at')->orderByDesc('id')
                    ->limit($this->kanbanLimits[$status] ?? 20)->get(),
            ];
        }

        return $columns;
    }

    public function loadMoreKanbanRecords(string $status): void
    {
        abort_unless(array_key_exists($status, LeadRequest::statuses()), 422);

        $this->kanbanLimits[$status] = ($this->kanbanLimits[$status] ?? 20) + 20;
    }

    public function moveSortedKanbanRecord(int $recordId, int $position, string $status): void
    {
        $this->moveKanbanRecord($recordId, $status);
    }

    public function moveKanbanRecord(int $recordId, string $status): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        Validator::make(['status' => $status], [
            'status' => ['required', Rule::in(array_keys(LeadRequest::statuses()))],
        ])->validate();

        $record = LeadRequestResource::getEloquentQuery()->findOrFail($recordId);
        abort_unless(LeadRequestResource::canEdit($record), 403);

        if ($record->status === $status) {
            return;
        }

        $record->update(['status' => $status]);
        $this->cachedTableRecords = null;
        unset($this->cachedTabs);

        Notification::make()
            ->title('Статус изменён: '.LeadRequest::statuses()[$status])
            ->success()
            ->send();
    }

    /** Кнопки создания нет: заявки приходят с сайта. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Два типа заявок — два разных сценария работы менеджера, поэтому
     * они разведены закладками (docs/07-design-review.md § 8.2).
     */
    public function getTabs(): array
    {
        return [
            'new' => Tab::make('Новые')
                ->modifyQueryUsing(fn ($query) => $query->where('status', LeadRequest::STATUS_NEW))
                ->badge(LeadRequest::where('status', LeadRequest::STATUS_NEW)->count()),

            'contract' => Tab::make('Контрактное производство')
                ->modifyQueryUsing(fn ($query) => $query->where('type', LeadRequest::TYPE_CONTRACT)),

            'wholesale' => Tab::make('Оптовые')
                ->modifyQueryUsing(fn ($query) => $query->where('type', LeadRequest::TYPE_WHOLESALE)),

            'all' => Tab::make('Все'),
        ];
    }
}
