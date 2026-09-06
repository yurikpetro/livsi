<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Filament\Support\MoneyField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Вариант товара отдельным разделом — для сквозной правки цен и артикулов.
 * Обычная работа идёт из карточки товара, там же связь с фотографиями.
 *
 * Цена в базе — целые копейки. Сгенерированная форма показывала их как есть
 * и с префиксом «$»: заказчик ввёл бы 990 и получил товар за 9,90 ₽.
 */
class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Товар и артикул')
                    ->schema([
                        Select::make('product_id')
                            ->label('Товар')
                            ->relationship('product', 'title')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('sku')
                            ->label('Артикул (SKU)')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true),

                        TextInput::make('barcode')
                            ->label('Штрихкод')
                            ->maxLength(64),
                    ])
                    ->columns(3),

                Section::make('Вариант и цена')
                    ->description('Подписи объёма и аромата показываются покупателю в выборе варианта — как есть.')
                    ->schema([
                        TextInput::make('option_volume')
                            ->label('Объём — подпись')
                            ->maxLength(64)
                            ->placeholder('200 мл'),

                        TextInput::make('option_aroma')
                            ->label('Аромат — подпись')
                            ->maxLength(64)
                            ->placeholder('сочная вишня'),

                        TextInput::make('volume_ml')
                            ->label('Объём, мл (число)')
                            ->numeric()
                            ->minValue(0),

                        MoneyField::make('price')->required(),

                        MoneyField::make('compare_at_price', 'Цена до скидки')
                            ->helperText('Показывается зачёркнутой. Пусто — скидки нет.'),
                    ])
                    ->columns(3),

                Section::make('Вес и габариты')
                    ->description('Нужны для расчёта доставки: пока не заполнены, стоимость посчитать нельзя.')
                    ->schema([
                        TextInput::make('weight_g')->label('Вес, г')->numeric()->minValue(0),
                        TextInput::make('length_mm')->label('Длина, мм')->numeric()->minValue(0),
                        TextInput::make('width_mm')->label('Ширина, мм')->numeric()->minValue(0),
                        TextInput::make('height_mm')->label('Высота, мм')->numeric()->minValue(0),
                    ])
                    ->columns(4),

                Section::make()
                    ->schema([
                        Toggle::make('is_default')
                            ->label('Вариант по умолчанию')
                            ->helperText('Предвыбран в карточке и в быстром просмотре.'),

                        Toggle::make('is_active')->label('Активен')->default(true),

                        TextInput::make('sort')->label('Порядок')->numeric()->default(0),
                    ])
                    ->columns(3),
            ]);
    }
}
