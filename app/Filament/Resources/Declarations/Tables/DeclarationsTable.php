<?php

namespace App\Filament\Resources\Declarations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeclarationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable()->sortable(),
                TextColumn::make('title')->label('На что выдана')->wrap()->toggleable(),
                TextColumn::make('products_count')->label('Товаров')->counts('products'),
                TextColumn::make('valid_until')
                    ->label('Действует до')
                    ->date('d.m.Y')
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => $record->isExpired() ? 'danger' : 'success'),
                IconColumn::make('file_path')
                    ->label('Файл')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-text')
                    ->falseIcon('heroicon-o-x-mark'),
                IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([
                //
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
