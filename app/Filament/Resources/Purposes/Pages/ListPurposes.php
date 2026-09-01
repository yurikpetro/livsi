<?php

namespace App\Filament\Resources\Purposes\Pages;

use App\Filament\Resources\Purposes\PurposeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurposes extends ListRecords
{
    protected static string $resource = PurposeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
