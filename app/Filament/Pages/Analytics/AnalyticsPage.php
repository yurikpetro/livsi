<?php

namespace App\Filament\Pages\Analytics;

use App\Analytics\AnalyticsException;
use App\Analytics\Metrika;
use App\Analytics\Period;
use App\Analytics\Webmaster;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Общее для отчётов раздела «Аналитика сайта»: период, кнопка «Обновить»
 * и блоки, каждый из которых может не загрузиться независимо от других.
 */
abstract class AnalyticsPage extends Page
{
    public const GROUP = 'Аналитика сайта';

    protected static string|\UnitEnum|null $navigationGroup = self::GROUP;

    /** Период по умолчанию у каждой страницы свой. */
    protected static string $defaultPeriod = '7d';

    #[Url(as: 'p')]
    public string $period = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        if (! isset(Period::OPTIONS[$this->period])) {
            $this->period = static::$defaultPeriod;
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = isset(Period::OPTIONS[$period]) ? $period : static::$defaultPeriod;
    }

    public function periodObject(): Period
    {
        return Period::make($this->period ?: static::$defaultPeriod);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Обновить')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function (): void {
                    Metrika::forget();
                    Webmaster::forget();
                    Notification::make()->title('Данные запрошены заново')->success()->send();
                }),
        ];
    }

    /**
     * Блок отчёта: данные или понятная причина, почему их нет.
     *
     * @template T
     *
     * @param  callable(): T  $load
     * @return array{data: T|null, error: ?string}
     */
    protected function block(callable $load): array
    {
        try {
            return ['data' => $load(), 'error' => null];
        } catch (AnalyticsException $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::warning('Аналитика: блок не загрузился.', ['page' => static::class, 'error' => $e->getMessage()]);

            return ['data' => null, 'error' => 'Не удалось загрузить данные. Нажмите «Обновить» через минуту.'];
        }
    }
}
