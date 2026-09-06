<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductImage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Фотографии товара.
 *
 * Раздела не было вовсе: таблица `product_images` поддерживает галерею
 * и главный кадр, но заполнить её из админки было нельзя.
 *
 * Производные форматы (AVIF/WebP и `srcset`) собирает `npm run images`,
 * поэтому загруженный здесь файл до первой сборки отдаётся как есть —
 * страница не ломается, но вес будет исходный.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Фотографии';

    protected static ?string $modelLabel = 'фотографию';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Файл')
                    ->image()
                    ->disk('public_images')
                    ->directory('img/catalog')
                    ->visibility('public')
                    ->maxSize(8192)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('До 8 МБ. Нужны чистые кадры без инфографики маркетплейсов.'),

                TextInput::make('alt')
                    ->label('Альт-текст')
                    ->maxLength(255)
                    ->helperText('Что на фото. Читают поисковики и программы для незрячих.'),

                Toggle::make('is_primary')
                    ->label('Главное фото')
                    ->helperText('Показывается в каталоге и в быстром просмотре.'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('path')->label('Фото')->square(),
                TextColumn::make('alt')->label('Альт-текст')->wrap()->placeholder('не заполнен'),
                IconColumn::make('is_primary')->label('Главное')->boolean(),
                TextColumn::make('sort')->label('Порядок'),
            ])
            ->headerActions([
                CreateAction::make()
                    // Первое загруженное фото само становится главным: иначе
                    // в каталоге у товара не будет картинки вообще.
                    ->mutateDataUsing(function (array $data): array {
                        $data['is_primary'] = $data['is_primary']
                            || ! $this->getOwnerRecord()->images()->where('is_primary', true)->exists();

                        return $data;
                    })
                    ->after(fn (ProductImage $record) => self::keepSinglePrimary($record)),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn (ProductImage $record) => self::keepSinglePrimary($record)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Главное фото ровно одно: витрина берёт первое и остальные бы игнорировала. */
    private static function keepSinglePrimary(ProductImage $record): void
    {
        if (! $record->is_primary) {
            return;
        }

        ProductImage::where('product_id', $record->product_id)
            ->whereKeyNot($record->getKey())
            ->update(['is_primary' => false]);
    }
}
