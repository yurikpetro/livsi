<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Fulfillment;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** История заказов клиента: что заказывал, когда забрал или не забрал, промокоды. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Заказы';

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
                TextColumn::make('number')->label('Номер')
                    ->description(fn (Order $record): string => Fulfillment::moscow($record->created_at, 'd.m.Y H:i') ?? ''),
                TextColumn::make('items_list')->label('Состав')->wrap()
                    ->state(fn (Order $record): string => $record->items->map(fn ($i) => $i->label().($i->quantity > 1 ? ' × '.$i->quantity : ''))->implode(', ')),
                TextColumn::make('total')->label('Сумма')->state(fn (Order $record): string => Money::rub((int) $record->total))
                    ->description(fn (Order $record): ?string => $record->promo_code ? 'промокод '.$record->promo_code : null),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state): string => Order::statuses()[$state] ?? $state)
                    ->description(fn (Order $record): ?string => $record->outcome()),
                TextColumn::make('fulfillment')->label('Доставка')->placeholder('—')
                    ->state(fn (Order $record): ?string => ($c = Fulfillment::current($record)) ? Fulfillment::label($c) : null)
                    ->description(fn (Order $record): ?string => ($since = Fulfillment::moscow(Fulfillment::since($record))) ? 'с '.$since : null),
            ]);
    }
}
