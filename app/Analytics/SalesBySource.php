<?php

namespace App\Analytics;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Заказы и выручка по источникам — из своих заказов, без Яндекса.
 *
 * Выручка — оплаченные заказы, кроме тех, по которым деньги возвращены.
 * Источник — то, что сайт запомнил в начале визита покупателя: метки
 * рекламы или адрес перехода. Заказы до обновления 17 знают только метки,
 * поэтому у старых заказов чаще «без источника».
 */
final class SalesBySource
{
    /**
     * @return array{
     *     channels: list<array{key: string, label: string, orders: int, paid: int, revenue: int, average: int}>,
     *     totals: array{orders: int, paid: int, revenue: int},
     *     campaigns: list<array{label: string, orders: int, paid: int, revenue: int}>,
     *     recent: list<array{number: string, id: int, at: Carbon, channel: string, detail: ?string, total: int, status: string}>
     * }
     */
    public static function report(Period $period): array
    {
        $orders = Order::query()
            ->whereBetween('created_at', [$period->from->copy()->utc(), $period->to->copy()->utc()])
            ->latest('id')
            ->get(['id', 'number', 'status', 'total', 'paid_at', 'utm', 'created_at']);

        $channels = [];
        $campaigns = [];
        $recent = [];
        $totals = ['orders' => 0, 'paid' => 0, 'revenue' => 0];

        foreach ($orders as $order) {
            $channel = TrafficChannel::of($order->utm);
            $paid = $order->paid_at !== null && $order->status !== Order::STATUS_REFUNDED;
            $revenue = $paid ? (int) $order->total : 0;

            $row = &$channels[$channel['key']];
            $row ??= ['key' => $channel['key'], 'label' => $channel['label'], 'orders' => 0, 'paid' => 0, 'revenue' => 0];
            $row['orders']++;
            $row['paid'] += (int) $paid;
            $row['revenue'] += $revenue;
            unset($row);

            if (in_array($channel['key'], ['ad_yandex', 'ad_google', 'ad', 'utm', 'email'], true)) {
                $label = $channel['label'].($channel['detail'] ? ' · '.$channel['detail'] : '');
                $campaign = &$campaigns[$label];
                $campaign ??= ['label' => $label, 'orders' => 0, 'paid' => 0, 'revenue' => 0];
                $campaign['orders']++;
                $campaign['paid'] += (int) $paid;
                $campaign['revenue'] += $revenue;
                unset($campaign);
            }

            $totals['orders']++;
            $totals['paid'] += (int) $paid;
            $totals['revenue'] += $revenue;

            if (count($recent) < 30) {
                $recent[] = [
                    'number' => (string) $order->number,
                    'id' => (int) $order->id,
                    'at' => $order->created_at,
                    'channel' => $channel['label'],
                    'detail' => $channel['detail'],
                    'total' => (int) $order->total,
                    'status' => Order::statuses()[$order->status] ?? (string) $order->status,
                ];
            }
        }

        $channels = array_map(fn (array $row): array => $row + ['average' => $row['paid'] > 0 ? intdiv($row['revenue'], $row['paid']) : 0], $channels);
        usort($channels, fn (array $a, array $b): int => [$b['revenue'], $b['orders']] <=> [$a['revenue'], $a['orders']]);
        $campaigns = array_values($campaigns);
        usort($campaigns, fn (array $a, array $b): int => [$b['revenue'], $b['orders']] <=> [$a['revenue'], $a['orders']]);

        return ['channels' => array_values($channels), 'totals' => $totals, 'campaigns' => $campaigns, 'recent' => $recent];
    }
}
