<?php

namespace App\Filament\Resources\ProductLines;

use App\Filament\Resources\ProductLines\Pages\CreateProductLine;
use App\Filament\Resources\ProductLines\Pages\EditProductLine;
use App\Filament\Resources\ProductLines\Pages\ListProductLines;
use App\Filament\Resources\ProductLines\Schemas\ProductLineForm;
use App\Filament\Resources\ProductLines\Tables\ProductLinesTable;
use App\Models\ProductLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductLineResource extends Resource
{
    protected static ?string $model = ProductLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|\UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Линейки';

    protected static ?string $modelLabel = 'линейку';

    protected static ?string $pluralModelLabel = 'Линейки';

    public static function form(Schema $schema): Schema
    {
        return ProductLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductLinesTable::configure($table);
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
            'index' => ListProductLines::route('/'),
            'create' => CreateProductLine::route('/create'),
            'edit' => EditProductLine::route('/{record}/edit'),
        ];
    }
}
