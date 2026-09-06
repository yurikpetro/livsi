<?php

namespace App\Filament\Resources\PromoRules\Tables;

use App\Filament\Resources\PromoRules\Schemas\PromoRuleForm;
use App\Models\PromoRule;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromoRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PromoRuleForm::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'gift' ? 'warning' : 'info'),

                // Порог в базе — копейки. Без пересчёта в таблице стояло «500000».
                TextColumn::make('threshold')
                    ->label('Порог')
                    ->state(fn (PromoRule $record) => Money::rub($record->threshold))
                    ->sortable(),

                TextColumn::make('gifts_count')
                    ->label('Подарков на выбор')
                    ->counts('gifts')
                    ->placeholder('—'),

                TextColumn::make('channel')
                    ->label('Где действует')
                    ->formatStateUsing(fn (string $state) => PromoRuleForm::CHANNELS[$state] ?? $state),

                TextColumn::make('period')
                    ->label('Срок')
                    ->state(fn (PromoRule $record) => match (true) {
                        $record->starts_at && $record->ends_at => $record->starts_at->format('d.m.y') . ' — ' . $record->ends_at->format('d.m.y'),
                        (bool) $record->ends_at               => 'до ' . $record->ends_at->format('d.m.y'),
                        (bool) $record->starts_at             => 'с ' . $record->starts_at->format('d.m.y'),
                        default                                => 'бессрочно',
                    }),

                IconColumn::make('is_active')->label('Активно')->boolean(),

                TextColumn::make('title')
                    ->label('Название для себя')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Тип')->options(PromoRuleForm::TYPES),
                SelectFilter::make('channel')->label('Канал')->options(PromoRuleForm::CHANNELS),
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
