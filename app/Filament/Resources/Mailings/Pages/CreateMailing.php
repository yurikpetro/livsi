<?php

namespace App\Filament\Resources\Mailings\Pages;

use App\Filament\Resources\Mailings\MailingResource;
use App\Filament\Support\CustomerActions;
use App\Models\Customer;
use App\Services\MailingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Рассылка всем, кто согласился. Выбранным клиентам — из раздела «Клиенты». */
class CreateMailing extends CreateRecord
{
    protected static string $resource = MailingResource::class;

    protected static ?string $title = 'Рассылка всем, кто дал согласие';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(MailingService::class)->create(CustomerActions::mailingData($data), Customer::query()->subscribed()->get(), CustomerActions::by());
        } catch (ValidationException $exception) {
            Notification::make()->title('Рассылка не создана')->body($exception->validator->errors()->first())->danger()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Рассылка запущена: письма уходят по 30 в минуту';
    }

    protected function getRedirectUrl(): string
    {
        return MailingResource::getUrl('view', ['record' => $this->record]);
    }
}
