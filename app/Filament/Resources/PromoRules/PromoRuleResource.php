<?php

namespace App\Filament\Resources\PromoRules;

use App\Filament\Resources\PromoRules\Pages\CreatePromoRule;
use App\Filament\Resources\PromoRules\Pages\EditPromoRule;
use App\Filament\Resources\PromoRules\Pages\ListPromoRules;
use App\Filament\Resources\PromoRules\Schemas\PromoRuleForm;
use App\Filament\Resources\PromoRules\Tables\PromoRulesTable;
use App\Models\PromoRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PromoRuleResource extends Resource
{
    protected static ?string $model = PromoRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Промо-правила';

    protected static ?string $modelLabel = 'правило';

    protected static ?string $pluralModelLabel = 'Промо-правила';

    public static function form(Schema $schema): Schema
    {
        return PromoRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromoRulesTable::configure($table);
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
            'index' => ListPromoRules::route('/'),
            'create' => CreatePromoRule::route('/create'),
            'edit' => EditPromoRule::route('/{record}/edit'),
        ];
    }
}
