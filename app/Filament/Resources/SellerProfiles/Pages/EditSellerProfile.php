<?php

namespace App\Filament\Resources\SellerProfiles\Pages;

use App\Filament\Resources\SellerProfiles\SellerProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSellerProfile extends EditRecord
{
    protected static string $resource = SellerProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
