<?php

namespace App\Filament\Resources\OrderReturns\Pages;

use App\Filament\Resources\OrderReturns\OrderReturnResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderReturns extends ListRecords
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
