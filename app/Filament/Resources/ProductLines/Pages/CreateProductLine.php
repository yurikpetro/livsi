<?php

namespace App\Filament\Resources\ProductLines\Pages;

use App\Filament\Resources\ProductLines\ProductLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductLine extends CreateRecord
{
    protected static string $resource = ProductLineResource::class;
}
