<?php

namespace App\Filament\Resources\Tasks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Код в адресе')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                // Сколько товаров использует ось: перед удалением значения
                // важно видеть, что оно не пустое.
                TextColumn::make('products_count')
                    ->label('Товаров')
                    ->counts('products')
                    ->sortable(),

                TextColumn::make('sort')->label('Порядок')->sortable(),

                IconColumn::make('is_active')->label('Активно')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активно'),
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
