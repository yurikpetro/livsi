<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CustomerActions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->record->name ?: (string) $this->record->email;
    }

    protected function getHeaderActions(): array
    {
        return [CustomerActions::issuePromo()];
    }
}
