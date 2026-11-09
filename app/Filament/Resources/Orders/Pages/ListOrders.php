<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\FulfillmentService;
use App\Support\Fulfillment;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'view')]
    public string $displayMode = 'table';

    /** @var array<string, int> */
    #[Locked]
    public array $kanbanLimits = [];

    public function mount(): void
    {
        if (! request()->has('view')) {
            $this->displayMode = session('orders.layout', 'table');
        }

        if (! in_array($this->displayMode, ['table', 'kanban'], true)) {
            $this->displayMode = 'table';
        }

        parent::mount();

        $this->normalizeKanbanTab();
    }

    public function setDisplayMode(string $displayMode): void
    {
        abort_unless(in_array($displayMode, ['table', 'kanban'], true), 422);

        $this->displayMode = $displayMode;
        session()->put('orders.layout', $displayMode);
        unset($this->cachedTabs);
        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));
        $this->normalizeKanbanTab();
    }

    private function normalizeKanbanTab(): void
    {
        if ($this->displayMode === 'kanban' && ! in_array($this->activeTab, ['paid', 'all'], true)) {
            $this->activeTab = 'paid';
            $this->updatedActiveTab();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make()->visible(fn (): bool => $this->displayMode !== 'kanban'),
            View::make('filament.pages.orders-kanban')->visible(fn (): bool => $this->displayMode === 'kanban'),
        ]);
    }

    public function getHeader(): ?ViewContract
    {
        return view('filament.pages.orders-header');
    }

    public function headerTabs(Schema $schema): Schema
    {
        return $schema->components([$this->getTabsContentComponent()]);
    }

    /** @return array<string, array{label: string, color: string|array, records: Collection<int, Order>, total: int, amount: int}> */
    public function getKanbanColumns(): array
    {
        $columns = [];

        foreach (Fulfillment::statuses() as $status => $label) {
            $query = (clone $this->getFilteredTableQuery())
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_REFUNDED])
                ->where(function (Builder $query) use ($status): void {
                    $query->where('fulfillment_status', $status);

                    if ($status === Fulfillment::AWAITING) {
                        $query->orWhere(fn (Builder $query): Builder => $query
                            ->whereNull('fulfillment_status')->where('status', Order::STATUS_PAID));
                    }
                });

            $columns[$status] = [
                'label' => $label,
                'color' => Fulfillment::color($status),
                'total' => (clone $query)->count(),
                'amount' => (int) (clone $query)->sum('total'),
                'records' => $query->reorder()->orderByDesc('created_at')->orderByDesc('id')
                    ->limit($this->kanbanLimits[$status] ?? 20)->get(),
            ];
        }

        return $columns;
    }

    public function loadMoreKanbanRecords(string $status): void
    {
        abort_unless(array_key_exists($status, Fulfillment::statuses()), 422);

        $this->kanbanLimits[$status] = ($this->kanbanLimits[$status] ?? 20) + 20;
    }

    public function moveSortedKanbanRecord(int $recordId, int $position, string $status): void
    {
        $this->moveKanbanRecord($recordId, $status);
    }

    public function moveKanbanRecord(int $recordId, string $status): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $record = OrderResource::getEloquentQuery()->findOrFail($recordId);
        abort_unless(OrderResource::canEdit($record), 403);

        Validator::make(['fulfillment_status' => $status], [
            'fulfillment_status' => ['required', Rule::in(array_keys(Fulfillment::options($record)))],
        ])->validate();

        app(FulfillmentService::class)->set($record, $status);
        $this->cachedTableRecords = null;
        unset($this->cachedTabs);

        Notification::make()->title('Доставка: '.Fulfillment::label($status))->success()->send();
    }

    /** Заказы создаёт покупатель — кнопки создания нет. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $tabs = [
            'paid' => Tab::make('Оплаченные')
                ->modifyQueryUsing(fn ($query) => $query->where('status', Order::STATUS_PAID))
                ->badge(Order::where('status', Order::STATUS_PAID)->count()),

            // Вкладки статуса доставки: что собрать, что отдать в доставку, что едет.
            'to_assemble' => Tab::make('Ждут сборки')
                ->modifyQueryUsing(fn ($query) => $query->where('status', Order::STATUS_PAID)
                    ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', Fulfillment::AWAITING)))
                ->badge(Order::where('status', Order::STATUS_PAID)
                    ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', Fulfillment::AWAITING))
                    ->count() ?: null)
                ->badgeColor('warning'),

            'assembled' => Tab::make('Собраны')
                ->modifyQueryUsing(fn ($query) => $query->where('fulfillment_status', Fulfillment::ASSEMBLED)),

            'in_delivery' => Tab::make('В доставке')
                ->modifyQueryUsing(fn ($query) => $query->whereIn('fulfillment_status', [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP])),

            // Открытые заявки на возврат; цифра — сколько ждут решения.
            'returns' => Tab::make('Возвраты')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('returns', fn ($returns) => $returns
                    ->whereIn('status', [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::RECEIVED])))
                ->badge(OrderReturn::query()->where('status', OrderReturn::REQUESTED)->count() ?: null)
                ->badgeColor('danger'),

            'awaiting' => Tab::make('Ждут оплаты')
                ->modifyQueryUsing(fn ($query) => $query->where('status', Order::STATUS_AWAITING_PAYMENT)),

            'all' => Tab::make('Все'),
        ];

        return $this->displayMode === 'kanban' ? array_intersect_key($tabs, array_flip(['paid', 'all'])) : $tabs;
    }
}
