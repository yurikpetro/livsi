<?php

namespace App\Filament\Resources\MissingPages\Pages;

use App\Filament\Resources\MissingPages\MissingPageResource;
use Filament\Resources\Pages\ListRecords;

class ListMissingPages extends ListRecords
{
    protected static string $resource = MissingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
