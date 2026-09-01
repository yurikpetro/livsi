<?php

namespace App\Filament\Resources\Purposes\Pages;

use App\Filament\Resources\Purposes\PurposeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPurpose extends EditRecord
{
    protected static string $resource = PurposeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
