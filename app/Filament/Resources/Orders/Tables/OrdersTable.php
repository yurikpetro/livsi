<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Support\FulfillmentAction;
use App\Filament\Support\ShipmentAction;
use App\Models\Order;
use App\Support\Fulfillment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->deferFilters(false)
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i', Fulfillment::TIMEZONE)
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
                        Order::STATUS_PAID => 'success',
                        Order::STATUS_AWAITING_PAYMENT => 'warning',
                        Order::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('payment')
                    ->label('Оплата')
                    ->state(fn (Order $record) => $record->lastPayment()?->statusLabel() ?? 'не начиналась'),

                TextColumn::make('fulfillment')
                    ->label('Доставка')
                    ->badge()
                    ->state(fn (Order $record): ?string => Fulfillment::current($record) ? Fulfillment::label(Fulfillment::current($record)) : null)
                    ->placeholder('—')
                    ->color(fn (Order $record): string|array => Fulfillment::color(Fulfillment::current($record)))
                    ->description(fn (Order $record): ?string => ($since = Fulfillment::moscow(Fulfillment::since($record))) ? 'с '.$since : null)
                    ->tooltip(fn (Order $record): ?string => Fulfillment::canChange($record) ? 'Нажмите, чтобы сменить статус' : null)
                    ->action(FulfillmentAction::change()),

                TextColumn::make('shipment')
                    ->label('Отправление')
                    ->state(fn (Order $record): string => $record->shipmentLabel())
                    ->description(fn (Order $record): ?string => $record->hasShipment()
                        ? $record->shipmentReference().($record->shipment_tracking_number ? ' · '.$record->shipment_tracking_number : '')
                        : $record->shipment_error),

                TextColumn::make('customer_email')
                    ->label('Почта')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(Order::statuses())->multiple(),
                SelectFilter::make('fulfillment')->label('Доставка')->options(Fulfillment::statuses())->multiple()
                    ->visible(fn (ListOrders $livewire): bool => $livewire->displayMode !== 'kanban')
                    ->query(fn (Builder $query, array $data): Builder => blank($data['values'] ?? null) ? $query
                        : $query->where(fn (Builder $query) => $query
                            ->whereIn('fulfillment_status', $data['values'])
                            ->when(in_array(Fulfillment::AWAITING, $data['values'], true), fn (Builder $query) => $query
                                ->orWhere(fn (Builder $query) => $query->whereNull('fulfillment_status')->where('status', Order::STATUS_PAID))))),
            ])
            ->recordActions([
                FulfillmentAction::advance(),
                ShipmentAction::make(),
                ShipmentAction::check(),
                EditAction::make(),
                Action::make('print')
                    ->label('Распечатать')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Order $record): string => route('admin.orders.print', $record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([FulfillmentAction::bulk()]),
            ]);
    }
}
