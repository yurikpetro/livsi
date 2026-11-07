<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Services\ReceiptService;
use App\Services\RefundService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
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
            ->modalDescription(fn (Order $record): string => trim('Чек возврата придёт покупателю на почту. '
                .(app(RefundService::class)->warning($record) ?? '')))
            ->modalSubmitActionLabel('Вернуть деньги')
            ->schema([
                Radio::make('amount')->label('Сколько вернуть')->required()->default('full')
                    ->options(fn (Order $record): array => self::amounts($record))
                    ->visible(fn (Order $record): bool => app(RefundService::class)->deliveryToKeep($record) > 0),
                Textarea::make('reason')->label('Причина (видна в журнале заказа)')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (Order $record, array $data, Action $action): void {
                try {
                    $refund = app(RefundService::class)->refund($record, $data['reason'], (string) (auth()->user()?->email ?? 'админка'),
                        ($data['amount'] ?? 'full') === 'without_delivery');
                } catch (ValidationException $exception) {
                    Notification::make()->title('Деньги не возвращены')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }
                $refund->status === OrderReceipt::SUCCEEDED
                    ? Notification::make()->title('Деньги возвращены, заказ отменён')->success()->send()
                    : Notification::make()->title('Возврат оформлен — банк обрабатывает')->body('Статус обновится сам в течение нескольких минут.')->warning()->send();
                $record->refresh();
            });
    }

    /** @return array<string, string> */
    private static function amounts(Order $record): array
    {
        $paid = (int) (app(ReceiptService::class)->payment($record)?->amount ?? $record->total);
        $keep = app(RefundService::class)->deliveryToKeep($record);

        return [
            'full' => 'Вся сумма — '.Money::rub($paid),
            'without_delivery' => 'Без стоимости доставки — '.Money::rub($paid - $keep).' (удержать '.Money::rub($keep).')',
        ];
    }
}
