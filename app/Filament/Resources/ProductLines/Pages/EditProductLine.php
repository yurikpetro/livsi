<?php

namespace App\Filament\Resources\ProductLines\Pages;

use App\Filament\Resources\ProductLines\ProductLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductLine extends EditRecord
{
    protected static string $resource = ProductLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
