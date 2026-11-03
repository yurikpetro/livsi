<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoCodes\Schemas\PromoCodeForm;
use Filament\Resources\Pages\CreateRecord;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PromoCodeForm::toModel($data) + ['issued_by' => (string) (auth()->user()?->email ?? 'админка')];
    }
}
