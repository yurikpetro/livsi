<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\AnalyticsSettings;
use App\Analytics\Metrika;
use App\Analytics\YandexOAuth;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * «Посещаемость»: сколько людей, когда, откуда, из каких городов,
 * с каких устройств и что смотрели. Данные — Яндекс Метрика.
 */
class AnalyticsTraffic extends AnalyticsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Посещаемость';

    protected static ?string $title = 'Посещаемость сайта';

    protected static ?string $slug = 'analytics/traffic';

    protected string $view = 'filament.analytics.traffic';

    protected function getViewData(): array
    {
        $ready = app(YandexOAuth::class)->connected() && AnalyticsSettings::counterId() !== null;
        if (! $ready) {
            return ['ready' => false, 'range' => $this->periodObject()];
        }

        $period = $this->periodObject();
        $metrika = app(Metrika::class);

        return [
            'ready' => true,
            'range' => $period,
            'counter' => AnalyticsSettings::counterId(),
            'totals' => $this->block(fn () => $metrika->totals($period)),
            'daily' => $this->block(fn () => $metrika->daily($period)),
            'sources' => $this->block(fn () => $metrika->sources($period)),
            'engines' => $this->block(fn () => $metrika->searchEngines($period)),
            'referrers' => $this->block(fn () => $metrika->referrers($period)),
            'cities' => $this->block(fn () => $metrika->cities($period)),
            'devices' => $this->block(fn () => $metrika->devices($period)),
            'pages' => $this->block(fn () => $metrika->pages($period)),
            'recent' => $this->block(fn () => $metrika->recentVisits()),
        ];
    }
}
