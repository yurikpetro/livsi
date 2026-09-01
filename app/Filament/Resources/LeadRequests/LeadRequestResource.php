<?php

namespace App\Filament\Resources\LeadRequests;

use App\Filament\Resources\LeadRequests\Pages\CreateLeadRequest;
use App\Filament\Resources\LeadRequests\Pages\EditLeadRequest;
use App\Filament\Resources\LeadRequests\Pages\ListLeadRequests;
use App\Filament\Resources\LeadRequests\Schemas\LeadRequestForm;
use App\Filament\Resources\LeadRequests\Tables\LeadRequestsTable;
use App\Models\LeadRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeadRequestResource extends Resource
{
    protected static ?string $model = LeadRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Заявки';

    protected static ?string $modelLabel = 'заявку';

    protected static ?string $pluralModelLabel = 'Заявки';

    public static function form(Schema $schema): Schema
    {
        return LeadRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeadRequests::route('/'),
            'create' => CreateLeadRequest::route('/create'),
            'edit' => EditLeadRequest::route('/{record}/edit'),
        ];
    }
}
