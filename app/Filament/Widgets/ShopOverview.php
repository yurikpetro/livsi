<?php

namespace App\Filament\Widgets;

use App\Models\LeadRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockQuota;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Первое, что видно при входе в админку.
 *
 * По умолчанию там были две служебные плашки Filament — аккаунт и версия
 * фреймворка. Заказчику нужно другое: сколько заявок ждёт ответа, что
 * заканчивается на складе и где данные не заполнены.
 */
class ShopOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getStats(): array
    {
        $newLeads = LeadRequest::where('status', LeadRequest::STATUS_NEW)->count();

        // «Заканчивается» и «нет в наличии» считаем по той же формуле,
        // что и витрина: выделено минус зарезервировано минус продано.
        $quotas    = StockQuota::all();
        $outOfStock = $quotas->filter(fn (StockQuota $q) => $q->available() < 1)->count();
        $lowStock   = $quotas->filter(fn (StockQuota $q) => $q->available() > 0 && $q->isLow())->count();

        // Без веса и габаритов доставку посчитать нельзя — это блокер запуска.
        $withoutDimensions = ProductVariant::where(fn ($q) => $q->whereNull('weight_g')
            ->orWhereNull('length_mm')
            ->orWhereNull('width_mm')
            ->orWhereNull('height_mm'))->count();

        return [
            Stat::make('Новых заявок', $newLeads)
                ->description($newLeads > 0 ? 'Ждут ответа менеджера' : 'Все обработаны')
                ->color($newLeads > 0 ? 'warning' : 'success'),

            Stat::make('Активных товаров', Product::where('is_active', true)->count())
                ->description(Product::where('is_active', false)->count() . ' скрыто')
                ->color('gray'),

            Stat::make('Нет в наличии', $outOfStock)
                ->description($lowStock . ' заканчивается')
                ->color($outOfStock > 0 ? 'danger' : 'success'),

            Stat::make('Без веса и габаритов', $withoutDimensions)
                ->description($withoutDimensions > 0 ? 'Доставку по ним не посчитать' : 'Все заполнены')
                ->color($withoutDimensions > 0 ? 'danger' : 'success'),
        ];
    }
}
