<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('primary_image')
                    ->label('Фото')
                    ->state(fn (Product $record) => $record->primaryImage()?->path)
                    ->square(),

                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record) => $record->short_description)
                    ->wrap(),

                TextColumn::make('line.title')
                    ->label('Линейка')
                    ->badge()
                    ->sortable(),

                TextColumn::make('badge')
                    ->label('Бейдж')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'new'   => 'Новинка',
                        'best'  => 'Бестселлер',
                        'pro'   => 'PRO',
                        default => '—',
                    }),

                // Минимальная цена по вариантам. В базе копейки, показываем рубли.
                TextColumn::make('price_from')
                    ->label('Цена от')
                    ->state(fn (Product $record) => Money::rub($record->priceFrom())),

                TextColumn::make('variants_count')
                    ->label('Вариантов')
                    ->counts('variants'),

                IconColumn::make('is_pro')
                    ->label('PRO')
                    ->boolean(),

                IconColumn::make('is_bundle')
                    ->label('Набор')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('rating')
                    ->label('Рейтинг')
                    ->formatStateUsing(fn (Product $record) => $record->rating
                        ? $record->rating . ' · ' . $record->reviews_count . ' на ' . $record->reviews_source
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('slug')
                    ->label('Адрес (slug)')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_line_id')
                    ->label('Линейка')
                    ->relationship('line', 'title'),

                TernaryFilter::make('is_pro')->label('Для мастеров (PRO)'),
                TernaryFilter::make('is_bundle')->label('Наборы'),
                TernaryFilter::make('is_active')->label('Активен'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
