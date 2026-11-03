<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Models\PromoCode;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Личные промокоды клиента: кто и когда выдал, использован ли и в каком заказе. */
class PromoCodesRelationManager extends RelationManager
{
    protected static string $relationship = 'promoCodes';

    protected static ?string $title = 'Промокоды';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('liveOrders'))
            ->columns([
                TextColumn::make('code')->label('Код')->copyable()->weight('bold'),
                TextColumn::make('benefit')->label('Что даёт')->state(fn (PromoCode $record): string => $record->benefit()),
                TextColumn::make('state')->label('Состояние')->badge()->state(fn (PromoCode $record): string => $record->stateLabel())
                    ->color(fn (string $state): string => $state === 'действует' ? 'success' : 'gray')
                    ->description(fn (PromoCode $record): ?string => $record->liveOrders->first()?->number ? 'в заказе '.$record->liveOrders->first()->number : null),
                TextColumn::make('expires_at')->label('До')->placeholder('бессрочно')
                    ->state(fn (PromoCode $record): ?string => $record->expires_at?->timezone('Europe/Moscow')->format('d.m.Y')),
                TextColumn::make('issued')->label('Выдал')
                    ->state(fn (PromoCode $record): string => trim(($record->issued_by ?? '').' · '.$record->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i'), ' ·'))
                    ->description(fn (PromoCode $record): ?string => $record->note),
            ]);
    }
}
