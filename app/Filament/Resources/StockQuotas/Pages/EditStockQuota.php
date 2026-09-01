<?php

namespace App\Filament\Resources\StockQuotas\Pages;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStockQuota extends EditRecord
{
    protected static string $resource = StockQuotaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
