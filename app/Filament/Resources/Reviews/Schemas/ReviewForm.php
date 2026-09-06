<?php

namespace App\Filament\Resources\Reviews\Schemas;

use App\Models\Review;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Наполнение одной карточки отзыва на главной.
 *
 * Композиция блока в макете фиксированная: три карточки на своих местах.
 * Поэтому здесь правится только содержимое — место в блоке, порядок,
 * включение и удаление карточек недоступны, иначе вёрстка разъедется.
 */
class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Что видно на карточке')
                    ->description(fn (?Review $record) => $record
                        ? 'Место в блоке: ' . $record->slotLabel()
                        : null)
                    ->schema([
                        TextInput::make('caption')
                            ->label('Строка над цитатой')
                            ->maxLength(80)
                            ->placeholder('FRESH / Мультипенка')
                            ->helperText('Показывается капсом. Пусто — соберётся из линейки и названия товара.'),

                        Select::make('rating')
                            ->label('Оценка')
                            ->options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★'])
                            ->default(5)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Столько звёзд и будет в правом верхнем углу карточки.'),

                        Textarea::make('text')
                            ->label('Текст отзыва')
                            ->required()
                            ->rows(4)
                            ->maxLength(fn (?Review $record) => $record?->slot === 'main' ? 320 : 220)
                            ->columnSpanFull()
                            ->helperText(fn (?Review $record) => $record?->slot === 'main'
                                ? 'Крупная цитата. До 320 знаков — длиннее не помещается в карточку.'
                                : 'До 220 знаков — длиннее не помещается в узкую карточку.'),

                        TextInput::make('author')
                            ->label('Имя')
                            ->required()
                            ->maxLength(40)
                            ->helperText('Первая буква попадёт в квадрат рядом с именем.'),

                        TextInput::make('role_caption')
                            ->label('Подпись под именем')
                            ->maxLength(40)
                            ->placeholder('Подтверждённая покупка')
                            ->helperText('В макете здесь «Подтверждённая покупка» или, например, «Мастер педикюра».'),

                        Select::make('accent')
                            ->label('Оформление')
                            ->options(Review::ACCENTS)
                            ->default('fresh')
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('В макете различается только цвет звёзд у карточки PRO.'),
                    ])
                    ->columns(3),

                Section::make('Фотография')
                    ->description(fn (?Review $record) => $record?->slot === 'main'
                        ? 'В большой карточке снимок лежит поверх фона под наклоном — нужен PNG с прозрачным фоном, иначе будет виден прямоугольник.'
                        : 'В узкой карточке снимок занимает колонку справа целиком. Подойдёт обычная фотография.')
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Файл')
                            ->image()
                            ->disk('public_images')
                            ->directory('img/reviews')
                            ->visibility('public')
                            ->maxSize(8192)
                            ->columnSpanFull(),

                        Select::make('product_id')
                            ->label('Товар из отзыва')
                            ->relationship('product', 'title')
                            ->searchable()
                            ->preload()
                            ->placeholder('без привязки')
                            ->helperText('Нужен только чтобы подставить строку над цитатой, если та пустая.'),
                    ])
                    ->columns(2),

                Section::make('Откуда отзыв')
                    ->description('На витрине не показывается — нужно, чтобы вы сами помнили источник каждого отзыва.')
                    ->collapsed()
                    ->schema([
                        Select::make('source')
                            ->label('Площадка')
                            ->options([
                                'ozon'        => 'Ozon',
                                'wildberries' => 'Wildberries',
                                'yandex'      => 'Яндекс Маркет',
                                'site'        => 'Прислали напрямую',
                            ])
                            ->placeholder('не указана'),

                        TextInput::make('source_label')
                            ->label('Уточнение')
                            ->maxLength(100)
                            ->visible(fn (Get $get) => filled($get('source'))),
                    ])
                    ->columns(2),
            ]);
    }
}
