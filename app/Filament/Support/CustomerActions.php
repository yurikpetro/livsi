<?php

namespace App\Filament\Support;

use App\Models\Customer;
use App\Services\MailingService;
use App\Services\PromoCodeService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Выдать промокод и сделать рассылку — одному клиенту или всем отмеченным. */
final class CustomerActions
{
    public static function issuePromo(): Action
    {
        return Action::make('issuePromo')
            ->label('Выдать промокод')
            ->icon(Heroicon::OutlinedTicket)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->modalHeading(fn (Customer $record): string => 'Промокод для '.($record->name ?: $record->email))
            ->modalDescription('Личный и одноразовый: подходит только этому клиенту, виден ему в личном кабинете.')
            ->modalSubmitActionLabel('Выдать')
            ->schema([...PromoFields::make(), TextInput::make('note')->label('Для себя: за что')->maxLength(200)])
            ->action(function (Customer $record, array $data): void {
                $promo = app(PromoCodeService::class)->issue(PromoFields::spec($data), $record, self::by());
                Notification::make()->title('Выдан промокод '.$promo->code)->body($promo->benefit())->success()->send();
            });
    }

    public static function issuePromoBulk(): BulkAction
    {
        return BulkAction::make('issuePromoBulk')
            ->label('Выдать промокод')
            ->icon(Heroicon::OutlinedTicket)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->modalHeading('Промокод каждому отмеченному')
            ->modalSubmitActionLabel('Выдать')
            ->modalDescription('Каждый получит свой личный одноразовый код — он появится у него в личном кабинете. Письмо при этом не уходит: для письма — «Рассылка».')
            ->schema([...PromoFields::make(), TextInput::make('note')->label('Для себя: за что')->maxLength(200)])
            ->action(function (Collection $records, array $data): void {
                foreach ($records as $customer) {
                    app(PromoCodeService::class)->issue(PromoFields::spec($data), $customer, self::by());
                }
                Notification::make()->title('Выдано промокодов: '.$records->count())->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function mailingBulk(): BulkAction
    {
        return BulkAction::make('mailingBulk')
            ->label('Рассылка')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize(fn (): bool => (bool) auth()->user()?->is_admin)
            ->modalHeading('Письмо отмеченным клиентам')
            ->modalDescription('Уйдёт только тем, кто дал согласие на рассылку и не отписался. Остальные будут пропущены — их видно в итогах рассылки.')
            ->schema(self::mailingFields())
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                try {
                    $mailing = app(MailingService::class)->create(self::mailingData($data), $records, self::by());
                } catch (ValidationException $exception) {
                    Notification::make()->title('Рассылка не создана')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('Рассылка запущена: '.$mailing->total.' писем')
                    ->body('Письма уходят по 30 в минуту. Итоги — в разделе «Рассылки».')->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** @return array<int, mixed> */
    public static function mailingFields(): array
    {
        return [
            TextInput::make('subject')->label('Тема письма')->required()->minLength(3)->maxLength(200),
            Textarea::make('body')->label('Текст')->required()->minLength(10)->rows(8)
                ->helperText('Пустая строка — новый абзац. Обращение по имени и ссылка «отписаться» добавятся сами.'),
            Toggle::make('with_promo')->label('Приложить личный промокод каждому')->live(),
            Section::make('Промокод')->schema(PromoFields::make('promo_'))
                ->visible(fn (Get $get): bool => (bool) $get('with_promo'))->columns(2),
        ];
    }

    public static function mailingData(array $data): array
    {
        return ['subject' => (string) $data['subject'], 'body' => (string) $data['body'],
            'promo' => ! empty($data['with_promo']) ? PromoFields::spec($data, 'promo_') : null];
    }

    public static function by(): string
    {
        return (string) (auth()->user()?->email ?? 'админка');
    }
}
