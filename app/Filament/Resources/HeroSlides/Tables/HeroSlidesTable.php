<?php

namespace App\Filament\Resources\HeroSlides\Tables;

use App\Models\HeroSlide;
use App\Support\Media;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class HeroSlidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                ImageColumn::make('desktop_image')->label('Компьютер')->imageHeight(54)
                    ->state(fn (HeroSlide $record): ?string => $record->desktop_image ? Media::fallback($record->desktop_image) : null),
                ImageColumn::make('mobile_image')->label('Телефон')->imageHeight(54)
                    ->state(fn (HeroSlide $record): ?string => $record->mobile_image ? Media::fallback($record->mobile_image) : null)
                    ->placeholder('нет — режется из картинки для компьютера'),
                TextColumn::make('code')->label('Линейка')->formatStateUsing(fn (string $state): string => mb_strtoupper($state))->weight('bold'),
                TextColumn::make('title')->label('Заголовок')
                    ->state(fn (HeroSlide $record): string => trim($record->title_first.' '.$record->title_second.' '.$record->title_accent)),
                IconColumn::make('mobile_ready')->label('Своя для телефона')->boolean()
                    ->state(fn (HeroSlide $record): bool => filled($record->mobile_image)),
                ToggleColumn::make('is_active')->label('Показывать'),
            ])
            ->recordActions([
                EditAction::make()->label('Изменить'),
            ]);
    }
}
