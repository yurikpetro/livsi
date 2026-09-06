<?php

namespace App\Filament\Resources\LeadRequests\Tables;

use App\Models\LeadRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LeadRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Получена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => LeadRequest::types()[$state] ?? $state)
                    ->color(fn (string $state) => $state === LeadRequest::TYPE_CONTRACT ? 'info' : 'gray'),

                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                TextColumn::make('contact')
                    ->label('Связь')
                    ->searchable()
                    ->copyable(),

                // Одна колонка на оба типа: у опта это город, у производства — категория.
                TextColumn::make('subject')
                    ->label('Предмет')
                    ->state(fn (LeadRequest $record) => $record->type === LeadRequest::TYPE_CONTRACT
                        ? trim(($record->categoryLabel() ?? '—') . ' · ' . ($record->planned_volume ?? 'объём не указан'))
                        : trim(($record->city ?? '—') . ' · ' . ($record->sales_format ?? 'формат не указан'))),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => LeadRequest::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        LeadRequest::STATUS_NEW         => 'warning',
                        LeadRequest::STATUS_IN_PROGRESS => 'info',
                        LeadRequest::STATUS_DONE        => 'success',
                        LeadRequest::STATUS_REJECTED    => 'danger',
                        default                         => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип заявки')
                    ->options(LeadRequest::types()),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(LeadRequest::statuses())
                    ->multiple(),
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
