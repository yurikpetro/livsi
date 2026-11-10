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

        // До какого дня у Яндекса вообще есть статистика запросов. Дни после
        // него — «данных нет», а не ноль показов.
        $until = $this->block(fn () => $webmaster->dataUntil())['data'];
        $queries = $this->block(fn () => $webmaster->popular($period));

        // За выбранный период пусто, потому что данные у Яндекса кончаются
        // раньше, — показываем последнюю неделю, которая у него есть.
        $latest = null;
        if (($queries['data']['rows'] ?? null) === [] && $until !== null && $until < $period->date1()) {
            $latest = $this->block(fn () => $webmaster->popular(null));
        }

        return [
            'ready' => true,
            'range' => $period,
            'until' => $until,
            'summary' => $this->block(fn () => $webmaster->summary()),
            'history' => $this->block(fn () => $webmaster->historyUntil($period, $until)),
            'queries' => $queries,
            'latest' => $latest,
            'problems' => $this->block(fn () => $webmaster->problems()),
            'events' => $this->block(fn () => $webmaster->searchEvents()),
            'brand' => self::BRAND,
        ];
    }
}
