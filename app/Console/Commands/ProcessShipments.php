<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Monitoring\ShipmentNotifications;
use App\Services\ShipmentAutomation;
use App\Support\Fulfillment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('shipments:process {--limit=50 : Максимум отправлений за запуск} {--dry-run : Только показать количество, ничего не отправлять}')]
#[Description('Восстановить очередь новых отправлений и проверить статусы доставки')]
class ProcessShipments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShipmentAutomation $shipments, ShipmentNotifications $notifications): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $due = fn (Builder $query): Builder => $query->where(fn (Builder $check): Builder => $check
            ->whereNull('shipment_checked_at')->orWhere('shipment_checked_at', '<=', now()->subMinutes(5)));
        $orders = Order::query()->where('shipment_automatic', true)
            ->where(function (Builder $query) use ($due): void {
                $query->where(function (Builder $pending) use ($due): void {
                    $pending->where('status', Order::STATUS_PAID)->whereNull('apiship_order_id')->whereNull('shipment_external_id')
                        ->where(function (Builder $state) use ($due): void {
                            $state->whereNull('shipment_state')->orWhere('shipment_state', 'queued')
                                ->orWhere(fn (Builder $unknown): Builder => $unknown
                                    ->whereIn('shipment_state', ['creating', 'unknown'])->where($due));
                        });
                })->orWhere(function (Builder $tracking) use ($due): void {
                    $tracking->where(fn (Builder $id): Builder => $id->whereNotNull('apiship_order_id')->orWhereNotNull('shipment_external_id'))
                        ->where($due)
                        ->where(fn (Builder $state): Builder => $state->whereNull('shipment_state')->orWhere('shipment_state', '!=', 'cancelled'))
                        ->where(fn (Builder $state): Builder => $state->whereNull('shipment_external_id')
                            ->orWhereNull('fulfillment_status')->orWhereNotIn('fulfillment_status', [Fulfillment::DELIVERED, Fulfillment::RETURNED]))
                        ->where(fn (Builder $state): Builder => $state->whereNull('shipment_provider_status')
                            ->orWhereNotIn('shipment_provider_status', ShipmentAutomation::TERMINAL_STATUSES));
                });
            })->orderBy('id')->limit($limit)->get();
        $pending = Order::query()->where('shipment_automatic', true)->whereNotNull('shipment_event_key')
            ->where(fn (Builder $query): Builder => $query->whereNull('shipment_notified_key')
                ->orWhereColumn('shipment_event_key', '!=', 'shipment_notified_key'))
            ->orderBy('id')->limit($limit)->get();
        if ($this->option('dry-run')) {
            $this->info('Отправлений для обработки: '.$orders->count().'; уведомлений: '.$pending->count().'.');

            return self::SUCCESS;
        }
        foreach ($orders as $order) {
            $shipments->queue($order);
        }
        foreach ($pending as $order) {
            $notifications->queue($order);
        }
        $this->info('Проверено отправлений: '.$orders->count().'; уведомлений: '.$pending->count().'.');

        return self::SUCCESS;
    }
}
