<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\Order;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Покупатель')
                    ->searchable()
                    ->description(fn (Order $record) => $record->customer_phone),

                TextColumn::make('total')
                    ->label('Сумма')
                    ->state(fn (Order $record) => Money::rub($record->total))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Order::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Order::STATUS_PAID             => 'success',
                        Order::STATUS_AWAITING_PAYMENT => 'warning',
                        Order::STATUS_CANCELLED        => 'danger',
                        default                        => 'gray',
                    }),

                TextColumn::make('payment')
                    ->label('Оплата')
                    ->state(fn (Order $record) => $record->lastPayment()?->statusLabel() ?? 'не начиналась'),

                TextColumn::make('customer_email')
                    ->label('Почта')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(Order::statuses())->multiple(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
