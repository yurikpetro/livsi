<?php

namespace App\Filament\Resources\UgcItems\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Блок «Ты и LIVSI» на главной — карточки с галереями фото и видео.
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
                    ->saveUploadedFileUsing(self::storeMedia(...))
                    ->label('Обложка карточки и видео')
                    ->image()
                    ->disk('public_images')
                    ->directory('img/ugc')
                    ->visibility('public')
                    ->maxSize(8192)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('До 8 МБ. Вертикальный кадр смотрится в блоке лучше.'),

                FileUpload::make('video_path')
                    ->saveUploadedFileUsing(self::storeMedia(...))
                    ->label('Основной ролик')
                    ->disk('public_images')
                    ->directory('media/ugc')
                    ->visibility('public')
                    ->acceptedFileTypes(['video/mp4', 'video/webm'])
                    ->maxSize(30720)
                    ->columnSpanFull()
                    ->helperText('MP4 (H.264) или WebM, до 30 МБ. Показывается, если галерея ниже пуста; без ролика показывается обложка.'),

                FileUpload::make('media_paths')
                    ->saveUploadedFileUsing(self::storeMedia(...))
                    ->label('Фото и видео внутри карточки')
                    ->disk('public_images')
                    ->directory('media/ugc')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'])
                    ->multiple()
                    ->reorderable()
                    ->maxParallelUploads(1)
                    ->maxFiles(20)
                    ->maxSize(30720)
                    ->columnSpanFull()
                    ->helperText('До 20 фото и видео, до 30 МБ каждый. Перетаскивайте файлы, чтобы менять порядок. Заполненная галерея показывается вместо основного кадра; обложка используется как постер видео. Формат 9:16.'),

                TextInput::make('alt')
                    ->label('Описание карточки / альт-текст')
                    ->maxLength(255)
                    ->helperText('Описание изображения и подпись внутри окна просмотра.'),

                Select::make('product_id')
                    ->label('Товар')
                    ->relationship('product', 'title')
                    ->searchable()
                    ->preload()
                    ->placeholder('без привязки')
                    ->helperText('Плашка товара поверх галереи с ценой и быстрым просмотром.'),

                TextInput::make('sort')->label('Порядок')->numeric()->default(0),

                Toggle::make('is_active')->label('Активна')->default(true),
            ])
            ->columns(2);
    }

    private static function storeMedia(FileUpload $component, TemporaryUploadedFile $file): string
    {
        $path = $component->saveUploadedFile($file);
        if (! $path || ! $component->getDisk()->exists($path)) {
            throw ValidationException::withMessages([
                $component->getStatePath() => 'Не удалось сохранить файл. Повторите загрузку; если ошибка остаётся, проверьте доступ к папке загрузок.',
            ]);
        }

        return $path;
    }
}
