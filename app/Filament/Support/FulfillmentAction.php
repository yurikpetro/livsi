<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Services\FulfillmentService;
use App\Support\Fulfillment;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Кнопки статуса доставки: следующий шаг в строке таблицы, выбор статуса
 * в карточке и массовая смена у отмеченных заказов.
 */
class FulfillmentAction
{
    /** «→ Собран»: один клик на складе, без окна подтверждения. Ошибку исправляют в карточке. */
    public static function advance(): Action
    {
        return Action::make('advanceFulfillment')
            ->label(fn (Order $record): string => '→ '.Fulfillment::shortLabel(Fulfillment::next($record)))
            ->tooltip(fn (Order $record): string => 'Отметить «'.Fulfillment::label(Fulfillment::next($record)).'»')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => Fulfillment::next($record) !== null)
            ->action(function (Order $record, Action $action): void {
                $next = Fulfillment::next($record);
                self::apply($record, $next, null, $action);
                Notification::make()
                    ->title($record->number.' → «'.Fulfillment::label($next).'»')
                    ->body('Покупатель увидит новый статус на странице заказа.')
                    ->success()->send();
            });
    }

    /** Выбор любого статуса с комментарием — для карточки заказа. */
    public static function change(): Action
    {
        return Action::make('changeFulfillment')
            ->label('Статус доставки')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => Fulfillment::canChange($record))
            ->modalHeading(fn (Order $record): string => 'Статус доставки · '.$record->number)
            ->modalDescription(fn (Order $record): string => 'Сейчас: «'.Fulfillment::label(Fulfillment::current($record)).'». Покупатель увидит новый статус на странице заказа.')
            ->modalSubmitActionLabel('Сохранить')
            ->fillForm(fn (Order $record): array => [
                'status' => Fulfillment::next($record) ?? Fulfillment::current($record),
            ])
            ->schema(fn (Order $record): array => [
                Select::make('status')
                    ->label('Новый статус')
                    ->options(Fulfillment::options($record))
                    ->selectablePlaceholder(false)
                    ->required(),
                Textarea::make('comment')
                    ->label('Комментарий')
                    ->helperText('Виден только в админке, покупатель его не видит.')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                self::apply($record, $data['status'], $data['comment'] ?? null, $action);
                Notification::make()->title('Статус доставки: «'.Fulfillment::label($data['status']).'»')->success()->send();
            });
    }

    /** Массовая смена у отмеченных заказов. Неоплаченные пропускаются с пояснением. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('bulkFulfillment')
            ->label('Статус доставки')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->modalHeading('Статус доставки у отмеченных заказов')
            ->modalSubmitActionLabel('Сохранить')
            ->schema([
                Select::make('status')
                    ->label('Новый статус')
                    ->options(Fulfillment::statuses())
                    ->default(Fulfillment::ASSEMBLED)
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $service = app(FulfillmentService::class);
                $done = 0;
                $skipped = [];

                foreach ($records as $record) {
                    if (! Fulfillment::canChange($record)
                        || ($data['status'] === Fulfillment::AT_PICKUP && Fulfillment::isCourier($record))) {
                        $skipped[] = $record->number;

                        continue;
                    }

                    $service->set($record, $data['status']);
                    $done++;
                }

                $notification = Notification::make()
                    ->title('«'.Fulfillment::label($data['status']).'»: '.$done.' из '.$records->count())
                    ->body($skipped ? 'Пропущены: '.implode(', ', $skipped).' — не оплачены или для курьера нет шага «в пункте выдачи».' : null);

                ($skipped ? $notification->warning() : $notification->success())->send();
            });
    }

    private static function apply(Order $record, ?string $status, ?string $comment, Action $action): void
    {
        try {
            app(FulfillmentService::class)->set($record, (string) $status, $comment);
        } catch (ValidationException $exception) {
            Notification::make()->title('Статус не изменён')->body($exception->validator->errors()->first())->danger()->send();
            $action->halt();
        }

        $record->refresh();
    }
}
