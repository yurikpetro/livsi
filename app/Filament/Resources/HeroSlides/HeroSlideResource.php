<?php

namespace App\Filament\Resources\HeroSlides;

use App\Filament\Resources\HeroSlides\Pages\EditHeroSlide;
use App\Filament\Resources\HeroSlides\Pages\ListHeroSlides;
use App\Filament\Resources\HeroSlides\Schemas\HeroSlideForm;
use App\Filament\Resources\HeroSlides\Tables\HeroSlidesTable;
use App\Models\HeroSlide;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Баннеры первого экрана главной: по одному на линейку. Новые не создаются и
 * не удаляются — под каждый баннер есть вкладка линейки на главной; баннер
 * можно скрыть.
 */
class HeroSlideResource extends Resource
{
    protected static ?string $model = HeroSlide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Баннеры на главной';

    protected static ?string $modelLabel = 'баннер';

    protected static ?string $pluralModelLabel = 'Баннеры на главной';

    protected static ?string $breadcrumb = 'Баннеры на главной';

    public static function form(Schema $schema): Schema
    {
        return HeroSlideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HeroSlidesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHeroSlides::route('/'),
            'edit' => EditHeroSlide::route('/{record}/edit'),
        ];
    }
}
