<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Support\Fulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Принят на склад» — посылка вернулась (покупатель не забрал) или товар
 * вернули после получения и деньги за него уже возвращены.
 *
 * Товар снова в продаже на сайте (квота), коды «Честного знака» освобождаются:
 * у не полученной посылки второго чека не было и коды из оборота не выходили,
 * а у возврата после получения их вернул в оборот чек возврата.
 */
class StockReturnService
{
    public function accept(Order $record, string $by, string $source = OrderFulfillmentEvent::SOURCE_MANUAL): Order
    {
        return DB::transaction(function () use ($record, $by, $source): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);
            if ($order->returned_to_stock_at) {
                $this->fail('Возврат уже принят на склад '.$order->returned_to_stock_at->timezone('Europe/Moscow')->format('d.m.Y H:i').'.');
            }
            if (! $order->canReturnToStock()) {
                $this->fail('Принять на склад можно вернувшуюся посылку или товар, за который уже вернули деньги.');
            }
            $order->load('items.marks');

            $pieces = 0;
            foreach ($order->items as $item) {
                $quota = StockQuota::query()->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
                $pieces += (int) $item->quantity;
                if (! $quota || $quota->sold < 1) {
                    continue;
                }
                $back = min((int) $item->quantity, (int) $quota->sold);
                $quota->decrement('sold', $back);
                StockQuotaMovement::create(['stock_quota_id' => $quota->id, 'type' => 'adjust', 'qty' => $back,
                    'comment' => 'Возврат по заказу '.$order->number.' принят на склад']);
            }
            $codes = $order->marks()->count();
            $order->marks()->delete();
            $order->forceFill(['returned_to_stock_at' => now(), 'returned_to_stock_by' => mb_substr($by, 0, 120)])->save();

            OrderFulfillmentEvent::create([
                'order_id' => $order->id,
                'user_id' => $source === OrderFulfillmentEvent::SOURCE_MANUAL ? auth()->id() : null,
                'status' => Fulfillment::current($order) ?? Fulfillment::RETURNED,
                'source' => $source,
                'comment' => mb_substr('Принят на склад: '.$by.'. Товар снова в продаже ('.$pieces.' шт.)'
                    .($codes ? ', коды «Честного знака» освобождены: '.$codes : '').'.', 0, 500),
            ]);

            return $order->fresh();
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['stock' => $message]);
    }
}
