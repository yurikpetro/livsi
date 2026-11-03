<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\FulfillmentAction;
use App\Filament\Support\ShipmentAction;
use App\Models\OrderIssue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /** Удаление заказа недопустимо: он основание для чека и для отчётности. */
    protected function getHeaderActions(): array
    {
        return [
            FulfillmentAction::change(),
            ShipmentAction::make(),
            ShipmentAction::check(),
            Action::make('print')
                ->label('Распечатать заказ')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('admin.orders.print', $this->record))
                ->openUrlInNewTab(),

            Action::make('reportIssue')
                ->label('Записать обращение')
                ->schema([
                    Select::make('kind')
                        ->label('Тип обращения')
                        ->options(OrderIssue::kinds())
                        ->required(),
                    Textarea::make('details')
                        ->label('Что произошло')
                        ->maxLength(500)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    if ($data['kind'] === OrderIssue::REFUND_REQUEST && ! $this->record->isPaid()) {
                        throw ValidationException::withMessages(['kind' => 'Запрос на возврат можно записать только для оплаченного заказа.']);
                    }

                    $this->record->issues()->create([
                        'kind' => $data['kind'],
                        'details' => $data['details'],
                        'user_id' => auth()->id(),
                    ]);

                    Notification::make()->title('Обращение записано')->success()->send();
                }),
        ];
    }
}
