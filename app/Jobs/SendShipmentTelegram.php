<?php

namespace App\Jobs;

use App\Models\Order;
use App\Monitoring\ShipmentNotifications;
use App\Monitoring\Telegram;
use App\Monitoring\TelegramFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SendShipmentTelegram implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(Telegram $telegram, ShipmentNotifications $notifications): void
    {
        $lock = Cache::lock('shipment-notification:'.$this->orderId, 60);
        try {
            if (! $lock->get()) {
                $this->release(10);

                return;
            }
            $order = Order::find($this->orderId);
            if (! $order || ! $order->shipment_automatic || ! $order->shipment_event_key
                || $order->shipment_notified_key === $order->shipment_event_key
                || ! hash_equals($order->shipment_event_key, $notifications->key($order))) {
                return;
            }
            if (! $telegram->topic('shipments')) {
                throw new RuntimeException('Не задан TELEGRAM_TOPIC_SHIPMENTS: отправка в общую ленту запрещена.');
            }
            $telegram->send($notifications->toHtml($order), 'shipments');
            $order->forceFill(['shipment_notified_key' => $order->shipment_event_key, 'shipment_notified_at' => now()])->save();
        } catch (Throwable $exception) {
            TelegramFailure::record($exception, ['topic' => 'shipments', 'order_id' => $this->orderId, 'attempt' => $this->attempts()]);
            if ($this->job && $this->attempts() < $this->tries) {
                $this->release($this->attempts() * 60);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        TelegramFailure::record($exception ?? new RuntimeException('Уведомление об отправлении не доставлено.'), [
            'topic' => 'shipments', 'order_id' => $this->orderId, 'stage' => 'worker',
        ]);
    }
}
