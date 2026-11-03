<?php

namespace App\Monitoring;

use App\Filament\Resources\Orders\OrderResource;
use App\Jobs\SendShipmentTelegram;
use App\Models\Order;
use App\Services\DeliverySelection;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShipmentNotifications
{
    public function key(Order $order): string
    {
        return $order->shipmentNotificationKey();
    }

    public function queue(Order $record): void
    {
        try {
            $order = $record->fresh();
            if (! $order->shipment_automatic || ! in_array($order->shipment_state, ['created', 'unknown', 'failed', 'cancelled'], true)) {
                return;
            }
            $key = $this->key($order);
            if ($order->shipment_event_key !== $key) {
                $order->forceFill(['shipment_event_key' => $key])->save();
            }
            if ($order->shipment_notified_key === $key || ! app(Telegram::class)->isConfigured()) {
                return;
            }
            DB::afterCommit(function () use ($order): void {
                $job = new SendShipmentTelegram($order->id);
                try {
                    dispatch($job)
                        ->onConnection(config('livsi.telegram.payments_connection', 'database'));
                } catch (Throwable $exception) {
                    try {
                        app(UniqueLock::class)->release($job);
                    } catch (Throwable) {
                    }
                    TelegramFailure::record($exception, ['topic' => 'shipments', 'order_id' => $order->id, 'stage' => 'dispatch']);
                }
            });
        } catch (Throwable $exception) {
            TelegramFailure::record($exception, ['topic' => 'shipments', 'order_id' => $record->id, 'stage' => 'prepare']);
        }
    }

    public function toHtml(Order $order): string
    {
        $heading = match ($order->shipment_state) {
            'failed' => '❌ Отправление не создано',
            'unknown' => '⚠️ Результат создания отправления не подтверждён',
            'cancelled' => '🚫 Отправление отменено',
            default => $order->shipment_error ? '⚠️ Не удалось проверить отправление'
                : ($order->shipment_notified_at && ($order->shipment_provider_status || $order->shipment_status_code)
                    ? '🚚 Статус отправления изменился' : '📦 Отправление создано'),
        };
        $lines = ['Заказ: '.$order->number, 'Доставка: '.DeliverySelection::label($order->delivery_method),
            'Статус: '.$order->shipmentLabel()];
        if ($order->apiship_order_id) {
            $lines[] = 'ApiShip: '.$order->apiship_order_id;
        }
        if ($order->shipment_external_id) {
            $lines[] = 'Отправление: '.$order->shipmentReference();
        }
        if ($order->shipment_tracking_number) {
            $lines[] = 'Номер перевозчика: '.$order->shipment_tracking_number;
        }
        if ($order->shipment_error) {
            $lines[] = $order->shipment_error;
        }
        if (($order->lastPayment()?->payload['test'] ?? null) === true) {
            $lines[] = 'Оплата заказа тестовая. Режим доставки определяется подключением перевозчика.';
        }
        $url = ErrorReport::escape(OrderResource::getUrl('edit', ['record' => $order], panel: 'admin'));

        return '<b>'.ErrorReport::escape($heading).'</b>'."\n"
            .implode("\n", array_map(fn (string $line): string => ErrorReport::escape($line), $lines))
            ."\n\n".'<a href="'.$url.'">Открыть заказ в админке</a>';
    }
}
