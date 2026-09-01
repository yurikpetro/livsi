<?php

namespace App\Filament\Resources\StockQuotas;

use App\Filament\Resources\StockQuotas\Pages\CreateStockQuota;
use App\Filament\Resources\StockQuotas\Pages\EditStockQuota;
use App\Filament\Resources\StockQuotas\Pages\ListStockQuotas;
use App\Filament\Resources\StockQuotas\Schemas\StockQuotaForm;
use App\Filament\Resources\StockQuotas\Tables\StockQuotasTable;
use App\Models\StockQuota;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StockQuotaResource extends Resource
{
    protected static ?string $model = StockQuota::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'Склад';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Квота сайта';

    protected static ?string $modelLabel = 'квоту';

    protected static ?string $pluralModelLabel = 'Квота сайта';

    public static function form(Schema $schema): Schema
    {
        return StockQuotaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockQuotasTable::configure($table);
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
            'index' => ListStockQuotas::route('/'),
            'create' => CreateStockQuota::route('/create'),
            'edit' => EditStockQuota::route('/{record}/edit'),
        ];
    }
}
