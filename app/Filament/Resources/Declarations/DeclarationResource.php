<?php

namespace App\Filament\Resources\Declarations;

use App\Filament\Resources\Declarations\Pages\CreateDeclaration;
use App\Filament\Resources\Declarations\Pages\EditDeclaration;
use App\Filament\Resources\Declarations\Pages\ListDeclarations;
use App\Filament\Resources\Declarations\Schemas\DeclarationForm;
use App\Filament\Resources\Declarations\Tables\DeclarationsTable;
use App\Models\Declaration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeclarationResource extends Resource
{
    protected static ?string $model = Declaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Декларации';

    protected static ?string $modelLabel = 'декларацию';

    protected static ?string $pluralModelLabel = 'Декларации соответствия';

    public static function form(Schema $schema): Schema
    {
        return DeclarationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeclarationsTable::configure($table);
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
            'index' => ListDeclarations::route('/'),
            'create' => CreateDeclaration::route('/create'),
            'edit' => EditDeclaration::route('/{record}/edit'),
        ];
    }
}
