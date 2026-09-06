<?php

namespace App\Filament\Resources\LegalPages\Tables;

use App\Models\LegalPage;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LegalPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')
                    ->label('Документ')
                    ->searchable()
                    ->description(fn (LegalPage $record) => '/' . $record->slug),

                // Непроверенный текст должен быть виден в списке, а не в карточке.
                TextColumn::make('reviewed_at')
                    ->label('Проверка юристом')
                    ->badge()
                    ->state(fn (LegalPage $record) => $record->isDraft()
                        ? 'черновик разработчика'
                        : 'проверен ' . $record->reviewed_at->format('d.m.Y'))
                    ->color(fn (LegalPage $record) => $record->isDraft() ? 'danger' : 'success'),

                TextColumn::make('updated_at')
                    ->label('Редакция от')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                IconColumn::make('is_active')->label('На сайте')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('open')
                    ->label('Открыть на сайте')
                    ->icon(\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (LegalPage $record) => route('legal.' . $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (LegalPage $record) => $record->is_active),
            ]);
    }
}
