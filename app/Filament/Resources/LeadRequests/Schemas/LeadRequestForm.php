<?php

namespace App\Filament\Resources\LeadRequests\Schemas;

use App\Models\LeadRequest;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка заявки в админке.
 *
 * Заявка приходит с сайта, поэтому её содержимое менеджер не правит —
 * оно только для чтения. Менять можно статус и свой комментарий: это
 * и есть работа менеджера. Иначе в базе не останется того, что человек
 * реально отправил.
 */
class LeadRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Работа с заявкой')
                    ->schema([
                        Select::make('status')
                            ->label('Статус')
                            ->options(LeadRequest::statuses())
                            ->default(LeadRequest::STATUS_NEW)
                            ->selectablePlaceholder(false)
                            ->required(),

                        Textarea::make('manager_comment')
                            ->label('Комментарий менеджера')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Что прислал клиент')
                    ->schema([
                        Select::make('type')
                            ->label('Тип заявки')
                            ->options(LeadRequest::types())
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('name')->label('Имя')->disabled()->dehydrated(false),
                        TextInput::make('contact')->label('Связь')->disabled()->dehydrated(false),

                        // Опт
                        TextInput::make('city')
                            ->label('Город')
                            ->disabled()->dehydrated(false)
                            ->visible(fn ($record) => $record?->type === LeadRequest::TYPE_WHOLESALE),

                        // Не TextInput: в базе лежит код, а менеджеру нужно название.
                        Placeholder::make('sales_format_label')
                            ->label('Формат продаж')
                            ->content(fn ($record) => $record?->salesFormatLabel() ?? '—')
                            ->visible(fn ($record) => $record?->type === LeadRequest::TYPE_WHOLESALE),

                        // Контрактное производство
                        Placeholder::make('product_category_label')
                            ->label('Категория продукта')
                            ->content(fn ($record) => $record?->categoryLabel() ?? '—')
                            ->visible(fn ($record) => $record?->type === LeadRequest::TYPE_CONTRACT),

                        TextInput::make('planned_volume')
                            ->label('Планируемый объём')
                            ->disabled()->dehydrated(false)
                            ->visible(fn ($record) => $record?->type === LeadRequest::TYPE_CONTRACT),

                        Textarea::make('comment')
                            ->label(fn ($record) => $record?->type === LeadRequest::TYPE_CONTRACT
                                ? 'Задача клиента'
                                : 'Комментарий клиента')
                            ->rows(4)
                            ->disabled()->dehydrated(false)
                            ->columnSpanFull()
                            ->visible(fn ($record) => filled($record?->comment)),
                    ])
                    ->columns(2),

                Section::make('Служебное')
                    ->description('Нужно, если возникнет спор о согласии на обработку данных или вопрос об источнике трафика.')
                    ->collapsed()
                    ->schema([
                        Placeholder::make('consent_at_label')
                            ->label('Согласие получено')
                            ->content(fn ($record) => $record?->consent_at?->format('d.m.Y H:i') ?? '—'),

                        Placeholder::make('ip_label')
                            ->label('IP-адрес')
                            ->content(fn ($record) => $record?->ip ?? '—'),

                        Placeholder::make('utm_label')
                            ->label('Источник перехода')
                            ->content(fn ($record) => $record?->utm
                                ? collect($record->utm)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')
                                : 'метки не передавались')
                            ->columnSpanFull(),

                        Placeholder::make('user_agent_label')
                            ->label('Браузер')
                            ->content(fn ($record) => $record?->user_agent ?? '—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
