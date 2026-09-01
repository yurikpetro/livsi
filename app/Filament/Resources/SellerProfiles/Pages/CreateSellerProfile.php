<?php

namespace App\Filament\Resources\SellerProfiles\Pages;

use App\Filament\Resources\SellerProfiles\SellerProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSellerProfile extends CreateRecord
{
    protected static string $resource = SellerProfileResource::class;
}
