<?php

namespace App\Filament\Resources\UgcItems\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Блок «Ты и LIVSI» на главной — подборка картинок из админки.
 *
 * Диск указан явно: по умолчанию Filament грузит на `local`, откуда сайт
 * файлы не отдаёт вообще, и картинка отображалась бы битой.
 */
class UgcItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('image_path')
                    ->label('Изображение')
                    ->image()
                    ->disk('public_images')
                    ->directory('img/ugc')
                    ->visibility('public')
                    ->maxSize(8192)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('До 8 МБ. Вертикальный кадр смотрится в блоке лучше.'),

                TextInput::make('alt')
                    ->label('Альт-текст')
                    ->maxLength(255)
                    ->helperText('Что на картинке. Читают поисковики и программы для незрячих.'),

                Select::make('product_id')
                    ->label('Товар')
                    ->relationship('product', 'title')
                    ->searchable()
                    ->preload()
                    ->placeholder('без привязки')
                    ->helperText('Если выбран, картинка ведёт на карточку товара.'),

                TextInput::make('sort')->label('Порядок')->numeric()->default(0),

                Toggle::make('is_active')->label('Активна')->default(true),
            ])
            ->columns(2);
    }
}
