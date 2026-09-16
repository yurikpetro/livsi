<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка заказа.
 *
 * Состав и суммы менеджер не правит: это снимок покупки, и он же
 * основание для чека. Менять можно статус — это и есть работа менеджера.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Работа с заказом')
                    ->schema([
                        Select::make('status')
                            ->label('Статус')
                            ->options(Order::statuses())
                            ->selectablePlaceholder(false)
                            ->required(),

                        Textarea::make('comment')
                            ->label('Комментарий покупателя')
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Покупатель')
                    ->schema([
                        Placeholder::make('customer_name')->label('Имя')
                            ->content(fn (Order $record) => $record->customer_name),
                        Placeholder::make('customer_phone')->label('Телефон')
                            ->content(fn (Order $record) => $record->customer_phone),
                        Placeholder::make('customer_email')->label('Почта')
                            ->content(fn (Order $record) => $record->customer_email),
                        Placeholder::make('order_link')->label('Ссылка для покупателя')
                            ->content(fn (Order $record) => $record->accessUrl())
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Состав')
                    ->schema([
                        Placeholder::make('items')
                            ->hiddenLabel()
                            ->content(fn (Order $record) => new \Illuminate\Support\HtmlString(
                                $record->items
                                    ->map(fn ($i) => e($i->label())
                                        . ' × ' . $i->quantity
                                        . ' — ' . e(Money::rub($i->total))
                                        . ($i->is_gift ? ' (подарок)' : ''))
                                    ->implode('<br>'),
                            ))
                            ->columnSpanFull(),

                        Placeholder::make('totals')->label('Суммы')
                            ->content(fn (Order $record) => 'Товары ' . Money::rub($record->items_total)
                                . ($record->discount_total > 0 ? ' · скидка −' . Money::rub($record->discount_total) : '')
                                . ' · итого ' . Money::rub($record->total))
                            ->columnSpanFull(),
                    ]),

                Section::make('Оплата')
                    ->schema([
                        Placeholder::make('payments')
                            ->hiddenLabel()
                            ->content(fn (Order $record) => new \Illuminate\Support\HtmlString(
                                $record->payments->isEmpty()
                                    ? 'Попыток оплаты не было'
                                    : $record->payments
                                        ->map(fn ($p) => e($p->statusLabel())
                                            . ' · ' . e(Money::rub($p->amount))
                                            . ' · ' . e($p->external_id ?? 'без номера')
                                            . ' · ' . $p->created_at->format('d.m.Y H:i'))
                                        ->implode('<br>'),
                            ))
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),

                Section::make('Служебное')
                    ->description('Нужно при споре о согласии или вопросе об источнике перехода.')
                    ->collapsed()
                    ->schema([
                        Placeholder::make('consent')->label('Согласие получено')
                            ->content(fn (Order $record) => $record->consent_at?->format('d.m.Y H:i') ?? '—'),
                        Placeholder::make('marketing')->label('Согласие на рассылку')
                            ->content(fn (Order $record) => $record->marketing_consent_at?->format('d.m.Y H:i') ?? 'не давал'),
                        Placeholder::make('ip')->label('IP')
                            ->content(fn (Order $record) => $record->ip ?? '—'),
                        Placeholder::make('utm')->label('Источник')
                            ->content(fn (Order $record) => $record->utm
                                ? collect($record->utm)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')
                                : 'метки не передавались')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
