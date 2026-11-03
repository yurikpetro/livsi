<?php

namespace App\Filament\Resources\Mailings\Pages;

use App\Filament\Resources\Mailings\MailingResource;
use App\Filament\Support\CustomerActions;
use App\Services\MailingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMailings extends ListRecords
{
    protected static string $resource = MailingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testMail')->label('Пробное письмо себе')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                ->modalDescription(fn (): string => 'Придёт на '.auth()->user()?->email.' — так, как увидит клиент, с примером промокода.')
                ->schema(CustomerActions::mailingFields())
                ->action(function (array $data): void {
                    app(MailingService::class)->sendTest(CustomerActions::mailingData($data), (string) auth()->user()?->email);
                    Notification::make()->title('Пробное письмо отправлено на '.auth()->user()?->email)->success()->send();
                }),
            CreateAction::make()->label('Рассылка всем с согласием'),
        ];
    }
}
