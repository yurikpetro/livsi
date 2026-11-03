<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Monitoring\ShipmentNotifications;
use App\Services\ShipmentAutomation;
use App\Services\ShipmentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ShipmentAction
{
    public static function make(): Action
    {
        return Action::make('retryShipment')
            ->databaseTransaction(false)
            ->label('Повторить создание')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => $record->shipment_automatic && $record->isPaid()
                && ! $record->hasShipment() && $record->shipment_state === 'failed')
            ->action(function (Order $record, Action $action): void {
                try {
                    $queued = app(ShipmentAutomation::class)->retry($record);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Повтор недоступен')->body($exception->validator->errors()->first())->warning()->send();
                    $action->halt();
                }
                if ($queued) {
                    Notification::make()->title('Отправление поставлено в очередь')->success()->send();
                } else {
                    Notification::make()->title('Очередь временно недоступна')->body('Постановка будет повторена автоматически.')->warning()->send();
                }
                $record->refresh();
            });
    }

    public static function check(): Action
    {
        return Action::make('checkShipment')
            ->label(fn (Order $record): string => $record->hasShipment() ? 'Обновить отправление' : 'Проверить отправление')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => $record->hasShipment()
                || in_array($record->shipment_state, ['creating', 'unknown'], true))
            ->action(function (Order $record, Action $action): void {
                try {
                    $order = app(ShipmentService::class)->refresh($record);
                    app(ShipmentNotifications::class)->queue($order);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Отправление не подтверждено')->body($exception->validator->errors()->first())->warning()->send();
                    $action->halt();
                }
                Notification::make()->title($order->shipmentLabel())->body((string) $order->shipmentReference())->success()->send();
                $record->refresh();
            });
    }
}
