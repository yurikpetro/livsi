<?php

namespace App\Filament\Resources\LeadRequests\Pages;

use App\Filament\Resources\LeadRequests\LeadRequestResource;
use App\Models\LeadRequest;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;

class ListLeadRequests extends ListRecords
{
    protected static string $resource = LeadRequestResource::class;

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
