<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Заказы создаёт покупатель — кнопки создания нет. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'paid' => Tab::make('Оплаченные')
                ->modifyQueryUsing(fn ($query) => $query->where('status', Order::STATUS_PAID))
                ->badge(Order::where('status', Order::STATUS_PAID)->count()),

            'awaiting' => Tab::make('Ждут оплаты')
                ->modifyQueryUsing(fn ($query) => $query->where('status', Order::STATUS_AWAITING_PAYMENT)),

            'all' => Tab::make('Все'),
        ];
    }
}
