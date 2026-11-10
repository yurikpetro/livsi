<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * «Поиск Яндекса»: сколько раз сайт показан в выдаче, сколько кликов,
 * на каком месте и по каким запросам. Данные — Яндекс Вебмастер.
 */
class AnalyticsSearch extends AnalyticsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Поиск Яндекса';

    protected static ?string $title = 'Сайт в поиске Яндекса';

    protected static ?string $slug = 'analytics/search';

    protected string $view = 'filament.analytics.search';

    /** Вебмастер отстаёт на несколько дней — неделя бывает почти пустой. */
    protected static string $defaultPeriod = '30d';

    /** Слова бренда — такие запросы выделяются в таблице. */
    public const BRAND = '/livsi|ливси|лифси|livsy|ливсы/iu';

    protected function getViewData(): array
    {
        $period = $this->periodObject();
        if (! app(YandexOAuth::class)->connected()) {
            return ['ready' => false, 'range' => $period];
        }
        $webmaster = app(Webmaster::class);

        return [
            'ready' => true,
            'range' => $period,
            'summary' => $this->block(fn () => $webmaster->summary()),
            'history' => $this->block(fn () => $webmaster->history($period)),
            'queries' => $this->block(fn () => $webmaster->popular($period)),
            'brand' => self::BRAND,
        ];
    }
}
