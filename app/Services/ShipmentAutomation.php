<?php

namespace App\Services;

use App\Delivery\DirectShipmentService;
use App\Jobs\CreateOrderShipment;
use App\Models\Order;
use App\Monitoring\TelegramFailure;
use App\Support\Fulfillment;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShipmentAutomation
{
    public const TERMINAL_STATUSES = ['delivered', 'returned', 'deliveryCanceled', 'lost'];

    public static function isTerminal(Order $order): bool
    {
        return $order->shipment_state === 'cancelled'
            || in_array($order->shipment_provider_status, self::TERMINAL_STATUSES, true)
            || (DirectShipmentService::isDirectOrder($order)
                && in_array($order->fulfillment_status, [Fulfillment::DELIVERED, Fulfillment::RETURNED], true));
    }

    public function queue(Order $order): bool
    {
        if (! $order->shipment_automatic || (! $order->isPaid() && ! $order->hasShipment())) {
            return false;
        }
        $job = new CreateOrderShipment($order->id);
        try {
            dispatch($job)
                ->onConnection(config('livsi.shipments.connection', 'database'))
                ->afterCommit();

            return true;
        } catch (Throwable $exception) {
            try {
                app(UniqueLock::class)->release($job);
            } catch (Throwable) {
            }
            TelegramFailure::record($exception, ['topic' => 'shipments', 'order_id' => $order->id, 'stage' => 'shipment_dispatch']);

            return false;
        }
    }

    public function retry(Order $record): bool
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $order = DB::transaction(function () use ($record): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);
            if (! $order->shipment_automatic || ! $order->isPaid() || $order->hasShipment() || $order->shipment_state !== 'failed') {
                throw ValidationException::withMessages(['shipment' => 'Повтор доступен только для отклонённого автоматического отправления.']);
            }
            $order->forceFill(['shipment_state' => 'queued', 'shipment_error' => null, 'shipment_checked_at' => null])->save();

            return $order;
        });

        return $this->queue($order);
    }
}
