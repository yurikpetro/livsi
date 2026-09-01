<?php

namespace App\Filament\Resources\Purposes;

use App\Filament\Resources\Purposes\Pages\CreatePurpose;
use App\Filament\Resources\Purposes\Pages\EditPurpose;
use App\Filament\Resources\Purposes\Pages\ListPurposes;
use App\Filament\Resources\Purposes\Schemas\PurposeForm;
use App\Filament\Resources\Purposes\Tables\PurposesTable;
use App\Models\Purpose;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PurposeResource extends Resource
{
    protected static ?string $model = Purpose::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Назначения';

    protected static ?string $modelLabel = 'назначение';

    protected static ?string $pluralModelLabel = 'Назначения';

    public static function form(Schema $schema): Schema
    {
        return PurposeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurposesTable::configure($table);
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
            'index' => ListPurposes::route('/'),
            'create' => CreatePurpose::route('/create'),
            'edit' => EditPurpose::route('/{record}/edit'),
        ];
    }
}
