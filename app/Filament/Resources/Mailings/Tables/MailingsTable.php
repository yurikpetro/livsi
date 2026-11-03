<?php

namespace App\Filament\Resources\Mailings\Tables;

use App\Models\Mailing;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('subject')->label('Тема')->searchable()->wrap()
                    ->description(fn (Mailing $record): ?string => $record->promo_kind ? 'с личным промокодом' : null),
                TextColumn::make('created_at')->label('Когда')
                    ->state(fn (Mailing $record): string => $record->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i'))
                    ->description(fn (Mailing $record): ?string => $record->created_by),
                TextColumn::make('progress')->label('Отправлено')
                    ->state(fn (Mailing $record): string => $record->sent.' из '.$record->total)
                    ->description(fn (Mailing $record): ?string => $record->failed ? 'не ушло: '.$record->failed : null),
                TextColumn::make('status')->label('Статус')->badge()->state(fn (Mailing $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => $state === 'отправлена' ? 'success' : 'warning'),
            ])
            ->recordActions([ViewAction::make()->label('Итоги')]);
    }
}
