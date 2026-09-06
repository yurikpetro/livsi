<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Карточка товара — главный экран, с которым работает заказчик.
 *
 * Сгенерированная версия была нерабочей: поле `documents` осталось от колонки,
 * удалённой миграцией 000011, и сохранение падало с SQL-ошибкой; назначений
 * и задач не было вовсе, хотя это две из трёх осей навигации; линейка
 * вводилась числовым идентификатором.
 */
class ProductForm
{
    /** Бейдж на карточке — три значения, которые понимает витрина. */
    public const BADGES = [
        'new'  => 'Новинка',
        'best' => 'Бестселлер',
        'pro'  => 'PRO',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('title')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // Адрес подставляем только новому товару: у опубликованного
                            // смена адреса ломает ссылки и накопленную выдачу.
                            ->afterStateUpdated(function (Get $get, ?string $state, callable $set, ?Product $record) {
                                if (! $record && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Адрес страницы')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Часть адреса: /product/скраб-для-тела. Менять у опубликованного товара нельзя — сломаются ссылки.'),

                        Textarea::make('short_description')
                            ->label('Краткое описание')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Одна-две строки под названием в каталоге.'),

                        Select::make('badge')
                            ->label('Бейдж')
                            ->options(self::BADGES)
                            ->placeholder('без бейджа')
                            ->helperText('Показывается плашкой на карточке в каталоге.'),

                        TextInput::make('sort')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('Меньше — выше в списке.'),
                    ])
                    ->columns(2),

                Section::make('Навигация по каталогу')
                    ->description('Три независимые оси: линейка одна, назначений и задач может быть несколько. По ним работают фильтры и меню.')
                    ->schema([
                        Select::make('product_line_id')
                            ->label('Ароматическая линейка')
                            ->relationship('line', 'title')
                            ->searchable()
                            ->preload()
                            ->placeholder('без линейки')
                            ->helperText('У товара ровно одна линейка либо ни одной.'),

                        Toggle::make('is_pro')
                            ->label('Профессиональный (PRO)')
                            ->helperText('Отдельный флаг, а не пятая линейка.'),

                        Toggle::make('is_bundle')
                            ->label('Набор')
                            ->helperText('Состав набора задаётся отдельно.'),

                        Select::make('purposes')
                            ->label('Назначение')
                            ->relationship('purposes', 'title')
                            ->multiple()
                            ->preload()
                            ->helperText('Для чего средство: руки, стопы, лицо, тело, маникюр, педикюр.')
                            ->columnSpanFull(),

                        Select::make('tasks')
                            ->label('Задача')
                            ->relationship('tasks', 'title')
                            ->multiple()
                            ->preload()
                            ->helperText('Что средство делает: увлажнение, очищение, размягчение и так далее.')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Описание и состав')
                    ->schema([
                        TextInput::make('aroma')
                            ->label('Аромат')
                            ->maxLength(255),

                        TextInput::make('effect')
                            ->label('Эффект')
                            ->maxLength(255),

                        TextInput::make('shelf_life')
                            ->label('Срок годности')
                            ->maxLength(255)
                            ->placeholder('24 месяца'),

                        Textarea::make('active_ingredients')
                            ->label('Активные компоненты')
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('composition')
                            ->label('Состав (INCI)')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Полный состав по документации производителя. Придумывать нельзя: состав — обязательная маркировка.'),

                        Textarea::make('application')
                            ->label('Применение')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsed(),

                Section::make('Отзывы и рейтинг')
                    ->description('Рейтинг на витрине всегда показывается вместе с площадкой-источником: «★ 4,9 · 326 отзывов на Ozon». Отзывы собраны не на сайте, и выдавать их за свои нельзя.')
                    ->schema([
                        TextInput::make('rating')
                            ->label('Оценка')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->step(0.1)
                            // Оценка без источника на витрине не выводится вообще,
                            // поэтому три поля обязательны только вместе.
                            ->live(onBlur: true)
                            ->requiredWith('reviews_count,reviews_source'),

                        TextInput::make('reviews_count')
                            ->label('Количество отзывов')
                            ->numeric()
                            ->minValue(0)
                            ->requiredWith('rating'),

                        TextInput::make('reviews_source')
                            ->label('Площадка-источник')
                            ->maxLength(100)
                            ->placeholder('Ozon')
                            ->requiredWith('rating')
                            ->helperText('Обязательно, если указана оценка.'),
                    ])
                    ->columns(3),

                Section::make('Публикация, налоги и SEO')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->helperText('Выключенный товар пропадает из каталога и поиска.'),

                        DateTimePicker::make('published_at')
                            ->label('Опубликован')
                            ->seconds(false),

                        TextInput::make('vat_rate')
                            ->label('Ставка НДС, %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(30)
                            ->step(0.01)
                            // Пустая ставка не равна нулю: нулевая ставка — отдельный
                            // осознанный случай, а пустое поле означает «не выяснили».
                            ->helperText('Пусто — ставка ещё не определена, в чеке использовать нельзя. Ноль ставится осознанно.'),

                        TextInput::make('seo_title')
                            ->label('SEO: заголовок')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Пусто — берётся название товара.'),

                        TextInput::make('seo_description')
                            ->label('SEO: описание')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Пусто — берётся краткое описание.'),
                    ])
                    ->columns(3)
                    ->collapsed(),
            ]);
    }
}
