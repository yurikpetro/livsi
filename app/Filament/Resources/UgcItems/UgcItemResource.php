<?php

namespace App\Filament\Resources\UgcItems;

use App\Filament\Resources\UgcItems\Pages\CreateUgcItem;
use App\Filament\Resources\UgcItems\Pages\EditUgcItem;
use App\Filament\Resources\UgcItems\Pages\ListUgcItems;
use App\Filament\Resources\UgcItems\Schemas\UgcItemForm;
use App\Filament\Resources\UgcItems\Tables\UgcItemsTable;
use App\Models\UgcItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UgcItemResource extends Resource
{
    protected static ?string $model = UgcItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Галерея «Ты и LIVSI»';

    protected static ?string $modelLabel = 'кадр';

    protected static ?string $pluralModelLabel = 'Галерея «Ты и LIVSI»';

    public static function form(Schema $schema): Schema
    {
        return UgcItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UgcItemsTable::configure($table);
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
            'index' => ListUgcItems::route('/'),
            'create' => CreateUgcItem::route('/create'),
            'edit' => EditUgcItem::route('/{record}/edit'),
        ];
    }
}
