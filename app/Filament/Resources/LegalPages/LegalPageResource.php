<?php

namespace App\Filament\Resources\LegalPages;

use App\Filament\Resources\LegalPages\Pages\EditLegalPage;
use App\Filament\Resources\LegalPages\Pages\ListLegalPages;
use App\Filament\Resources\LegalPages\Schemas\LegalPageForm;
use App\Filament\Resources\LegalPages\Tables\LegalPagesTable;
use App\Models\LegalPage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LegalPageResource extends Resource
{
    protected static ?string $model = LegalPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Документы';

    protected static ?string $modelLabel = 'документ';

    protected static ?string $pluralModelLabel = 'Документы';

    /**
     * Набор документов фиксированный: их адреса объявлены в маршрутах,
     * и на них ссылаются чеки, письма и уведомление в Роскомнадзор.
     * Новый документ добавляется вместе с маршрутом, а не из админки.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Счётчик непроверенных текстов: до запуска их не должно остаться. */
    public static function getNavigationBadge(): ?string
    {
        $drafts = static::getModel()::whereNull('reviewed_at')->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return LegalPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LegalPagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalPages::route('/'),
            'edit'  => EditLegalPage::route('/{record}/edit'),
        ];
    }
}
