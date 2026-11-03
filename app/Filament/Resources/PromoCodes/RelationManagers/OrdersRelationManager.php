<?php

namespace App\Filament\Resources\PromoCodes\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Fulfillment;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Заказы с этим промокодом. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Заказы с этим кодом';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label('Номер')->description(fn (Order $record): string => Fulfillment::moscow($record->created_at, 'd.m.Y H:i') ?? ''),
                TextColumn::make('customer_name')->label('Покупатель')->description(fn (Order $record): ?string => $record->customer_email),
                TextColumn::make('promo_discount')->label('Скидка')
                    ->state(fn (Order $record): string => $record->promo_free_delivery ? 'бесплатная доставка' : Money::rub((int) $record->promo_discount)),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => Order::statuses()[$state] ?? $state),
            ]);
    }
}
