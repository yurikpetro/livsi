<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\AnalyticsSettings;
use App\Analytics\Metrika;
use App\Analytics\SalesBySource;
use App\Analytics\YandexOAuth;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * «Продажи и реклама»: заказы и выручка по источникам — из своих заказов,
 * плюс визиты по рекламным меткам из Метрики. Расходы Директа добавим,
 * когда запустится реклама.
 */
class AnalyticsSales extends AnalyticsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Продажи и реклама';

    protected static ?string $title = 'Продажи по источникам и реклама';

    protected static ?string $slug = 'analytics/sales';

    protected string $view = 'filament.analytics.sales';

    protected static string $defaultPeriod = '30d';

    protected function getViewData(): array
    {
        $period = $this->periodObject();
        $metrika = app(YandexOAuth::class)->connected() && AnalyticsSettings::counterId() !== null;

        return [
            'range' => $period,
            'sales' => $this->block(fn () => SalesBySource::report($period)),
            'metrika' => $metrika,
            'campaigns' => $metrika ? $this->block(fn () => app(Metrika::class)->campaigns($period)) : null,
        ];
    }
}
