<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoCodes\Schemas\PromoCodeForm;
use Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PromoCodeForm::toForm($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PromoCodeForm::toModel($data);
    }
}
