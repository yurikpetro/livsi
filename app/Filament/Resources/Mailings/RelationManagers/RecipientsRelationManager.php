<?php

namespace App\Filament\Resources\Mailings\RelationManagers;

use App\Models\MailingRecipient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Получатели';

    private const STATUSES = ['queued' => 'в очереди', 'sent' => 'отправлено', 'failed' => 'не ушло', 'skipped' => 'пропущен'];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'promoCode']))
            ->columns([
                TextColumn::make('email')->label('Почта')->searchable()
                    ->description(fn (MailingRecipient $record): ?string => $record->customer?->name),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'gray'
                    })
                    ->description(fn (MailingRecipient $record): ?string => $record->error),
                TextColumn::make('promoCode.code')->label('Промокод')->placeholder('—'),
                TextColumn::make('sent_at')->label('Отправлено')->placeholder('—')
                    ->state(fn (MailingRecipient $record): ?string => $record->sent_at?->timezone('Europe/Moscow')->format('d.m.Y H:i')),
            ])
            ->filters([SelectFilter::make('status')->label('Статус')->options(self::STATUSES)]);
    }
}
