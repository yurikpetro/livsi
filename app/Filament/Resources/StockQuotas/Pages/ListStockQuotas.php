<?php

namespace App\Filament\Resources\StockQuotas\Pages;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockQuotas extends ListRecords
{
    protected static string $resource = StockQuotaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
