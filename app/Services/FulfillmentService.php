<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Support\Fulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Единственное место, где меняется статус доставки.
 *
 * Пишет статус в заказ и строку в журнал в одной транзакции, под блокировкой
 * строки заказа — два менеджера, нажавшие кнопку одновременно, не создадут
 * разнобой между заказом и журналом.
 */
class FulfillmentService
{
    public function set(Order $record, string $status, ?string $comment = null, string $source = OrderFulfillmentEvent::SOURCE_MANUAL): Order
    {
        if ($source === OrderFulfillmentEvent::SOURCE_MANUAL) {
            abort_unless(auth()->user()?->is_admin, 403);
        }

        if (! array_key_exists($status, Fulfillment::statuses())) {
            $this->fail('Неизвестный статус доставки.');
        }

        $comment = filled($comment) ? mb_substr(trim($comment), 0, 500) : null;

        return DB::transaction(function () use ($record, $status, $comment, $source): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);

            if (! Fulfillment::canChange($order)) {
                $this->fail('Статус доставки можно ставить только оплаченному заказу.');
            }

            if (Fulfillment::current($order) === $status && $comment === null) {
                return $order;
            }

            $order->forceFill([
                'fulfillment_status' => $status,
                'fulfillment_status_at' => now(),
            ])->save();

            OrderFulfillmentEvent::create([
                'order_id' => $order->id,
                'user_id' => $source === OrderFulfillmentEvent::SOURCE_MANUAL ? auth()->id() : null,
                'status' => $status,
                'source' => $source,
                'comment' => $comment,
            ]);

            return $order;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['fulfillment_status' => $message]);
    }
}
