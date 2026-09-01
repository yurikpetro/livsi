<?php

namespace App\Filament\Resources\UgcItems\Pages;

use App\Filament\Resources\UgcItems\UgcItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUgcItem extends EditRecord
{
    protected static string $resource = UgcItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
