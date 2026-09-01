<?php

namespace App\Filament\Resources\UgcItems\Pages;

use App\Filament\Resources\UgcItems\UgcItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUgcItems extends ListRecords
{
    protected static string $resource = UgcItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
