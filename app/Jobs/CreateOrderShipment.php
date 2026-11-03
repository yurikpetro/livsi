<?php

namespace App\Jobs;

use App\Models\Order;
use App\Monitoring\ShipmentNotifications;
use App\Monitoring\TelegramFailure;
use App\Services\ShipmentAutomation;
use App\Services\ShipmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreateOrderShipment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 300;

    public int $backoff = 60;

    public function __construct(public int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(ShipmentService $shipments, ShipmentNotifications $notifications): void
    {
        $order = Order::find($this->orderId);
        if (! $order || ! $order->shipment_automatic || (! $order->isPaid() && ! $order->hasShipment())) {
            return;
        }
        if (ShipmentAutomation::isTerminal($order)) {
            $notifications->queue($order);

            return;
        }
        $order->forceFill(['shipment_checked_at' => now()])->save();
        try {
            if ($order->hasShipment() || in_array($order->shipment_state, ['creating', 'unknown'], true)) {
                $shipments->refreshAutomatically($order);
            } elseif (in_array($order->shipment_state, [null, 'queued'], true)) {
                $shipments->createAutomatically($order);
            }
        } catch (ValidationException $exception) {
            $this->recordFailure($order, $exception->validator->errors()->first());
        } catch (Throwable $exception) {
            $this->recordFailure($order, 'Не удалось обработать отправление. Требуется проверка настроек доставки.');
            TelegramFailure::record(new RuntimeException('Ошибка фонового отправления: '.get_class($exception)), [
                'topic' => 'shipments', 'order_id' => $this->orderId, 'stage' => 'shipment_worker',
            ]);
        }
        $order = $order->fresh();
        $notifications->queue($order);
        if (in_array($order->shipment_state, ['creating', 'unknown'], true) && $this->job && $this->attempts() < $this->tries) {
            $this->release($this->attempts() * 60);
        }
    }

    private function recordFailure(Order $record, string $message): void
    {
        $order = $record->fresh();
        if ($order->hasShipment()) {
            $order->forceFill(['shipment_error' => 'Не удалось обновить статус доставки. Следующая проверка выполнится автоматически.'])->save();

            return;
        }
        if (in_array($order->shipment_state, ['creating', 'unknown'], true)) {
            $order->forceFill(['shipment_state' => 'unknown', 'shipment_error' => $order->shipment_error
                ?: 'Результат создания пока не подтверждён. Выполняется автоматическая проверка.'])->save();

            return;
        }
        $order->forceFill(['shipment_state' => 'failed', 'shipment_error' => mb_substr($message, 0, 255)])->save();
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $order = Order::find($this->orderId);
            if ($order && $order->shipment_automatic) {
                $this->recordFailure($order, 'Фоновая обработка отправления остановилась. Требуется проверка очереди.');
                app(ShipmentNotifications::class)->queue($order->fresh());
            }
        } catch (Throwable $failure) {
            TelegramFailure::record(new RuntimeException('Не удалось сохранить результат фонового отправления.'), [
                'topic' => 'shipments', 'order_id' => $this->orderId, 'stage' => 'shipment_failed',
            ]);
        }
    }
}
