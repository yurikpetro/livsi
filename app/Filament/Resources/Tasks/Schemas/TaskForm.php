<?php

namespace App\Filament\Resources\Tasks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Ось каталога «задача». Код попадает в адрес фильтра, поэтому он
 * латиницей и уникален: по нему собираются ссылки в меню и фасетах.
 */
class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->maxLength(32)
                    ->unique(ignoreRecord: true)
                    ->alphaDash()
                    ->helperText('Латиница без пробелов — попадает в адрес фильтра. У используемого значения менять нельзя: сломаются ссылки.'),

                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Как показывается покупателю в фильтрах и на карточке.'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label('Активно')
                    ->default(true),
            ])
            ->columns(2);
    }
}
