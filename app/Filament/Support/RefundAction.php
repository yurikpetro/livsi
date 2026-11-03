<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Services\ReceiptService;
use App\Services\RefundService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/** «Отменить заказ и вернуть деньги» — только администратор, только из админки. */
class RefundAction
{
    public static function make(): Action
    {
        return Action::make('refundOrder')
            ->label('Отменить заказ и вернуть деньги')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->databaseTransaction(false)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->visible(fn (Order $record): bool => app(RefundService::class)->canRefund($record))
            ->modalHeading(fn (Order $record): string => 'Отменить заказ '.$record->number.' и вернуть деньги')
            ->modalDescription(fn (Order $record): string => trim('Покупателю вернётся вся сумма — '
                .Money::rub((int) (app(ReceiptService::class)->payment($record)?->amount ?? $record->total))
                .', чек возврата придёт ему на почту. '.(app(RefundService::class)->warning($record) ?? '')))
            ->modalSubmitActionLabel('Вернуть деньги')
            ->schema([
                Textarea::make('reason')->label('Причина (видна в журнале заказа)')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                try {
                    $refund = app(RefundService::class)->refund($record, $data['reason'], (string) (auth()->user()?->email ?? 'админка'));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Деньги не возвращены')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }
                $refund->status === OrderReceipt::SUCCEEDED
                    ? Notification::make()->title('Деньги возвращены, заказ отменён')->success()->send()
                    : Notification::make()->title('Возврат оформлен — ЮKassa обрабатывает')->body('Статус обновится сам в течение нескольких минут.')->warning()->send();
                $record->refresh();
            });
    }
}
