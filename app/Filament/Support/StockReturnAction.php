<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Services\StockReturnService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/** «Принят на склад» — вернувшаяся посылка проверена, товар снова в продаже. */
class StockReturnAction
{
    public static function make(): Action
    {
        return Action::make('returnToStock')
            ->label('Принят на склад')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => $record->canReturnToStock())
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => 'Возврат по заказу '.$record->number.' принят на склад')
            ->modalDescription('Сначала проверьте, что товар цел и упаковка в порядке. Товар вернётся в продажу на сайте, коды «Честного знака» освободятся для других заказов.')
            ->modalSubmitActionLabel('Принят на склад')
            ->action(function (Order $record, Action $action): void {
                try {
                    app(StockReturnService::class)->accept($record, (string) (auth()->user()?->email ?? 'админка'));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Не принято')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('Принят на склад — товар снова в продаже')->success()->send();
                $record->refresh();
            });
    }
}
