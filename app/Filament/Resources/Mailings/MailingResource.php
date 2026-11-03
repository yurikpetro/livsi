<?php

namespace App\Filament\Resources\Mailings;

use App\Filament\Resources\Mailings\Pages\CreateMailing;
use App\Filament\Resources\Mailings\Pages\ListMailings;
use App\Filament\Resources\Mailings\Pages\ViewMailing;
use App\Filament\Resources\Mailings\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\Mailings\Tables\MailingsTable;
use App\Filament\Support\CustomerActions;
use App\Models\Customer;
use App\Models\Mailing;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Рассылки клиентам: кто получил, кто пропущен, личные промокоды. */
class MailingResource extends Resource
{
    protected static ?string $model = Mailing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Рассылки';

    protected static ?string $modelLabel = 'рассылка';

    protected static ?string $pluralModelLabel = 'Рассылки';

    public static function form(Schema $schema): Schema
    {
        if ($schema->getOperation() === 'view') {
            return $schema->components([
                Section::make('Письмо')->columns(2)->schema([
                    Placeholder::make('progress')->label('Отправлено')
                        ->content(fn (Mailing $record): string => $record->sent.' из '.$record->total.($record->failed ? ' · не ушло: '.$record->failed : '').' · '.$record->statusLabel()),
                    Placeholder::make('who')->label('Кто и когда')
                        ->content(fn (Mailing $record): string => $record->created_by.' · '.$record->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i')),
                    Placeholder::make('promo')->label('Промокод')
                        ->content(fn (Mailing $record): string => $record->promo_kind
                            ? 'Личный каждому: '.(new PromoCode(['kind' => $record->promo_kind, 'value' => (int) $record->promo_value]))->benefit()
                                .($record->promo_days ? ', на '.$record->promo_days.' дн.' : ', бессрочно')
                            : 'без промокода'),
                    Placeholder::make('subject_view')->label('Тема')->content(fn (Mailing $record): string => $record->subject),
                    Placeholder::make('body_view')->label('Текст')->columnSpanFull()
                        ->content(fn (Mailing $record): HtmlString => new HtmlString(nl2br(e($record->body)))),
                ]),
            ]);
        }

        return $schema->components([
            Section::make('Письмо')->schema([
                Placeholder::make('audience')->label('Кому')
                    ->content(fn (): string => 'Всем, кто дал согласие на рассылку и не отписался: '.Customer::query()->subscribed()->count()
                        .'. Выбрать конкретных клиентов — в разделе «Клиенты»: отметить → «С отмеченными» → «Рассылка».'),
                ...CustomerActions::mailingFields(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return MailingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [RecipientsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailings::route('/'),
            'create' => CreateMailing::route('/create'),
            'view' => ViewMailing::route('/{record}'),
        ];
    }
}
