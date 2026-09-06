<?php

namespace App\Filament\Resources\FaqItems\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/** Блок вопросов и ответов на главной — «Всё важное до оформления». */
class FaqItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('question')
                    ->label('Вопрос')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Textarea::make('answer')
                    ->label('Ответ')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000)
                    ->columnSpanFull()
                    ->helperText('Коротко и по делу: блок читают перед оформлением заказа.'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0)
                    ->helperText('Меньше — выше в списке.'),

                Toggle::make('is_active')->label('Активен')->default(true),
            ])
            ->columns(2);
    }
}
