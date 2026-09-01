<?php

namespace App\Filament\Resources\ProductLines\Pages;

use App\Filament\Resources\ProductLines\ProductLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductLines extends ListRecords
{
    protected static string $resource = ProductLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
