<?php

namespace App\Filament\Resources\ProductLines\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ароматическая линейка: FRESH, SWEET, WARM, BASE.
 *
 * Цвета берутся из брендбука (стр. 38, 41) и участвуют в вёрстке лендинга
 * линейки и маркеров в меню. Свободное текстовое поле для цвета, как было
 * в сгенерированной форме, позволяло вписать что угодно.
 */
class ProductLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Линейка')
                    ->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->alphaDash()
                            ->helperText('Часть адреса: /line/fresh. Латиница без пробелов. У опубликованной линейки менять нельзя.'),

                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(64)
                            ->placeholder('FRESH'),

                        TextInput::make('subtitle')
                            ->label('Подзаголовок')
                            ->maxLength(255),

                        Textarea::make('description')
                            ->label('Описание')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Цвета из брендбука')
                    ->description('Фон используется на лендинге линейки и маркером в меню каталога. Цвет текста должен быть читаем на этом фоне.')
                    ->schema([
                        ColorPicker::make('color_bg')
                            ->label('Цвет фона')
                            ->hex(),

                        ColorPicker::make('color_ink')
                            ->label('Цвет текста')
                            ->hex(),
                    ])
                    ->columns(2),

                Section::make('Публикация и SEO')
                    ->schema([
                        Toggle::make('is_active')->label('Активна')->default(true),
                        TextInput::make('sort')->label('Порядок')->numeric()->default(0),
                        TextInput::make('seo_title')->label('SEO: заголовок')->maxLength(255),
                        TextInput::make('seo_description')->label('SEO: описание')->maxLength(255),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }
}
