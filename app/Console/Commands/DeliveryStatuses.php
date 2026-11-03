<?php

namespace App\Console\Commands;

use App\Delivery\DeliveryGateway;
use App\Delivery\DirectShipmentService;
use App\Models\Order;
use App\Support\Fulfillment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подтянуть статусы отправлений у служб — раз в 15 минут по расписанию.
 *
 * Берутся оплаченные заказы с отправлением, созданным напрямую, которые
 * ещё не получены и не вернулись, не старше 60 дней. Сбой по одному заказу
 * не останавливает остальные.
 */
class DeliveryStatuses extends Command
{
    protected $signature = 'delivery:statuses {--order= : номер одного заказа}';

    protected $description = 'Обновить статусы отправлений служб доставки';

    public function handle(DirectShipmentService $shipments): int
    {
        $query = Order::query()
            ->whereIn('shipment_provider', DeliveryGateway::PROVIDERS)
            ->whereNotNull('shipment_external_id')
            ->where('shipment_state', 'created')
            ->where('status', Order::STATUS_PAID)
            ->where(fn ($query) => $query->whereNull('fulfillment_status')
                ->orWhereNotIn('fulfillment_status', [Fulfillment::DELIVERED, Fulfillment::RETURNED]))
            ->where('created_at', '>=', now()->subDays(60))
            ->orderBy('shipment_synced_at');
        if ($this->option('order')) {
            $query->where('number', $this->option('order'));
        }

        $done = $failed = 0;
        foreach ($query->limit(300)->get() as $order) {
            try {
                $order = $shipments->sync($order);
                $done++;
                $this->line($order->number.': '.($order->shipment_status_name ?? '—').' → '.Fulfillment::statuses()[Fulfillment::current($order)]);
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('Статус отправления не обновлён.', ['order' => $order->number,
                    'provider' => $order->shipment_provider, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            }
        }
        $this->info("Обновлено: $done, с ошибкой: $failed.");

        return self::SUCCESS;
    }
}
