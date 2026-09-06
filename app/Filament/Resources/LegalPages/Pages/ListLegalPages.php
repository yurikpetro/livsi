<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use Filament\Resources\Pages\ListRecords;

class ListLegalPages extends ListRecords
{
    protected static string $resource = LegalPageResource::class;

    /** Набор документов фиксированный — создавать новые из админки нельзя. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
