<?php

namespace App\Filament\Resources\PromoRules\Pages;

use App\Filament\Resources\PromoRules\PromoRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoRules extends ListRecords
{
    protected static string $resource = PromoRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
