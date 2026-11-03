<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Fulfillment;
use App\Support\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Клиент')->schema([
                Placeholder::make('name')->label('Имя')->content(fn (Customer $record): string => $record->name ?: '—'),
                Placeholder::make('email')->label('Почта')->content(fn (Customer $record): string => $record->email ?: '—'),
                Placeholder::make('phone')->label('Телефон')->content(fn (Customer $record): string => $record->phone ? '+'.$record->phone : '—'),
                Placeholder::make('city')->label('Город')->content(fn (Customer $record): string => $record->city ?: '—'),
                Placeholder::make('account')->label('Аккаунт на сайте')
                    ->content(fn (Customer $record): string => $record->user_id ? 'зарегистрирован' : 'покупал гостем'),
                Placeholder::make('consent')->label('Рассылка')->content(fn (Customer $record): string => self::consent($record)),
                Placeholder::make('stats')->label('Покупки')->content(fn (Customer $record): string => self::stats($record))->columnSpanFull(),
                Textarea::make('note')->label('Заметка для себя')->rows(2)->maxLength(2000)->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    public static function consent(Customer $record): string
    {
        return match (true) {
            $record->canReceiveMarketing() => 'согласен с '.$record->marketing_consent_at->timezone('Europe/Moscow')->format('d.m.Y'),
            (bool) $record->unsubscribed_at => 'отписался '.$record->unsubscribed_at->timezone('Europe/Moscow')->format('d.m.Y'),
            default => 'согласия нет',
        };
    }

    public static function stats(Customer $record): string
    {
        $orders = $record->orders()->whereIn('status', [Order::STATUS_PAID, Order::STATUS_REFUNDED])->get();
        $delivered = $orders->filter(fn (Order $o): bool => $o->status === Order::STATUS_PAID && $o->fulfillment_status === Fulfillment::DELIVERED)->count();
        $returned = $orders->filter(fn (Order $o): bool => $o->fulfillment_status === Fulfillment::RETURNED)->count();
        $cancelled = $orders->filter(fn (Order $o): bool => $o->status === Order::STATUS_REFUNDED)->count();

        return 'Оплачено заказов: '.$orders->count().' на '.Money::rub((int) $orders->where('status', Order::STATUS_PAID)->sum('total'))
            .' · получено: '.$delivered.' · не забрал: '.$returned.' · отменено с возвратом денег: '.$cancelled;
    }
}
