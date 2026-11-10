<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\Period;
use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use App\Seo\Autopilot;
use App\Seo\SeoReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Пока автопилот работает по кнопке — второй раз не запускаем. */
    public const RUN_LOCK = 'seo:autopilot:manual';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('autopilot')->label('Запустить автопилот')->icon(Heroicon::OutlinedPlay)->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Запустить SEO-автопилот сейчас?')
                ->modalDescription('Он и так работает каждую ночь. Сейчас: проверит, что сайт открыт для поисковиков, приведёт в порядок карту сайта в Вебмастере и отправит страницы на переобход. Займёт 1–2 минуты, результат появится на этой странице.')
                ->modalSubmitActionLabel('Запустить')
                ->action(function (): void {
                    $lock = Cache::lock(self::RUN_LOCK, 600);
                    if (! $lock->get()) {
                        Notification::make()->title('Автопилот уже работает — обновите страницу через минуту.')->warning()->send();

                        return;
                    }
                    // После ответа браузеру: работа долгая, страница не должна ждать.
                    dispatch(function () use ($lock): void {
                        @set_time_limit(600);
                        try {
                            app(Autopilot::class)->run();
                        } finally {
                            $lock->forceRelease();
                        }
                    })->afterResponse();
                    Notification::make()->title('Автопилот запущен')->body('Через 1–2 минуты обновите страницу — здесь будет отчёт.')->success()->send();
                }),
            Action::make('report')->label('Скачать SEO-отчёт для Claude')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => self::download()),
            ...parent::getHeaderActions(),
        ];
    }

    /** Полный отчёт файлом: по нему Claude готовит следующие улучшения. */
    public static function download(): StreamedResponse
    {
        @set_time_limit(180);
        $markdown = app(SeoReport::class)->full();

        return response()->streamDownload(function () use ($markdown): void {
            echo $markdown;
        }, 'livsi-seo-'.now(Period::TZ)->format('Y-m-d').'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    protected function getViewData(): array
    {
        $period = $this->periodObject();
        $autopilot = Autopilot::state();
        if (! app(YandexOAuth::class)->connected()) {
            return ['ready' => false, 'range' => $period, 'autopilot' => $autopilot];
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
            'autopilot' => $autopilot,
        ];
    }
}
