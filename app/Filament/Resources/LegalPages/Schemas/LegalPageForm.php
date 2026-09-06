<?php

namespace App\Filament\Resources\LegalPages\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LegalPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Проверка юристом')
                    ->description('Тексты написаны разработчиком по типовому шаблону. Оферта — публичный договор, её формулировки связывают продавца. Отметьте дату после проверки: пока она пуста, документ считается черновиком.')
                    ->schema([
                        DateTimePicker::make('reviewed_at')
                            ->label('Проверено юристом')
                            ->seconds(false)
                            ->helperText('Пусто — черновик разработчика.'),

                        Toggle::make('is_active')
                            ->label('Показывать на сайте')
                            ->default(true)
                            ->helperText('Выключенный документ отдаёт 404, а ссылка на него в подвале пропадает.'),
                    ])
                    ->columns(2),

                Section::make('Заголовки')
                    ->schema([
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('menu_title')
                            ->label('Короткая подпись для подвала')
                            ->maxLength(120)
                            ->helperText('Пусто — берётся полное название.'),

                        Textarea::make('lede')
                            ->label('Абзац под заголовком')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('slug')
                            ->label('Адрес страницы')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Меняется только вместе с маршрутом: на адрес ссылаются чеки и письма.'),
                    ])
                    ->columns(2),

                Section::make('Текст документа')
                    ->schema([
                        RichEditor::make('body')
                            ->hiddenLabel()
                            ->required()
                            ->toolbarButtons([
                                'bold', 'italic', 'link', 'h2', 'h3',
                                'bulletList', 'orderedList', 'undo', 'redo',
                            ])
                            ->columnSpanFull()
                            ->helperText('Реквизиты продавца подставляются на страницу автоматически — дублировать их в тексте не нужно.'),
                    ]),

                Section::make('SEO и порядок')
                    ->collapsed()
                    ->schema([
                        TextInput::make('seo_title')->label('SEO: заголовок')->maxLength(255),
                        TextInput::make('seo_description')->label('SEO: описание')->maxLength(255),
                        TextInput::make('sort')->label('Порядок в подвале')->numeric()->default(0),
                    ])
                    ->columns(2),
            ]);
    }
}
