<?php

namespace App\Filament\Resources\PromoRules\Pages;

use App\Filament\Resources\PromoRules\PromoRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromoRule extends EditRecord
{
    protected static string $resource = PromoRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
