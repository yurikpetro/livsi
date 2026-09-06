<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use App\Models\ProductVariant;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sku')
            ->columns([
                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.title')
                    ->label('Товар')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('option')
                    ->label('Вариант')
                    ->state(fn (ProductVariant $record) => $record->optionLabel()),

                // ->money() Filament считает валютой доллар и значение десятичным:
                // 99000 копеек показывались как «$99,000.00».
                TextColumn::make('price')
                    ->label('Цена')
                    ->state(fn (ProductVariant $record) => Money::rub($record->price))
                    ->sortable(),

                TextColumn::make('compare_at_price')
                    ->label('Цена до скидки')
                    ->state(fn (ProductVariant $record) => Money::rub($record->compare_at_price))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('available')
                    ->label('Доступно')
                    ->state(fn (ProductVariant $record) => $record->available())
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),

                TextColumn::make('weight_g')
                    ->label('Вес, г')
                    ->placeholder('не задан')
                    ->sortable(),

                IconColumn::make('is_default')->label('По умолчанию')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),

                TextColumn::make('barcode')
                    ->label('Штрихкод')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Товар')
                    ->relationship('product', 'title')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')->label('Активен'),

                // Габариты нужны для доставки, и их отсутствие надо видеть списком.
                Filter::make('without_dimensions')
                    ->label('Без веса или габаритов')
                    ->query(fn (Builder $query) => $query->where(
                        fn (Builder $q) => $q->whereNull('weight_g')
                            ->orWhereNull('length_mm')
                            ->orWhereNull('width_mm')
                            ->orWhereNull('height_mm'),
                    )),
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
