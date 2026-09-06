<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Review;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Три карточки блока на главной — в том же порядке, в каком они на сайте.
 *
 * Ни создания, ни удаления, ни массовых действий: композиция блока
 * в макете фиксированная, а список здесь — просто вход в наполнение.
 */
class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('slot')
                    ->label('Место в блоке')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => Review::SLOTS[$state] ?? '—'),

                ImageColumn::make('image_path')->label('Фото')->square(),

                TextColumn::make('caption')
                    ->label('Строка над цитатой')
                    ->placeholder('соберётся из товара'),

                TextColumn::make('text')
                    ->label('Отзыв')
                    ->wrap()
                    ->limit(90),

                TextColumn::make('author')
                    ->label('Имя')
                    ->description(fn (Review $record) => $record->role_caption),

                TextColumn::make('rating')
                    ->label('Оценка')
                    ->formatStateUsing(fn (Review $record) => $record->stars()),
            ])
            ->recordActions([
                EditAction::make()->label('Изменить'),
            ]);
    }
}
