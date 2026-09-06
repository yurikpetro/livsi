<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\MoneyField;
use App\Models\ProductVariant;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Варианты товара прямо в карточке.
 *
 * Сгенерированная версия давала только поле SKU — задать цену, объём или
 * аромат было нельзя. Действия «связать / отвязать» убраны: вариант
 * принадлежит ровно одному товару, отвязка обнулила бы обязательную связь.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Варианты';

    protected static ?string $modelLabel = 'вариант';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('sku')
                            ->label('Артикул (SKU)')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true),

                        TextInput::make('barcode')
                            ->label('Штрихкод')
                            ->maxLength(64),

                        TextInput::make('option_volume')
                            ->label('Объём — как в выборе на сайте')
                            ->maxLength(64)
                            ->placeholder('200 мл'),

                        TextInput::make('option_aroma')
                            ->label('Аромат — как в выборе на сайте')
                            ->maxLength(64)
                            ->placeholder('сочная вишня'),

                        TextInput::make('volume_ml')
                            ->label('Объём, мл (число)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Для сортировки и фильтров.'),

                        MoneyField::make('price')
                            ->required(),

                        MoneyField::make('compare_at_price', 'Цена до скидки')
                            ->helperText('Показывается зачёркнутой. Пусто — скидки нет.'),
                    ])
                    ->columns(3),

                Section::make('Вес и габариты')
                    ->description('Нужны для расчёта доставки. Пока не заполнены, стоимость доставки посчитать нельзя.')
                    ->schema([
                        TextInput::make('weight_g')->label('Вес, г')->numeric()->minValue(0),
                        TextInput::make('length_mm')->label('Длина, мм')->numeric()->minValue(0),
                        TextInput::make('width_mm')->label('Ширина, мм')->numeric()->minValue(0),
                        TextInput::make('height_mm')->label('Высота, мм')->numeric()->minValue(0),
                    ])
                    ->columns(4)
                    ->collapsed(),

                Section::make()
                    ->schema([
                        Toggle::make('is_default')
                            ->label('Вариант по умолчанию')
                            ->helperText('Он предвыбран в карточке и в быстром просмотре.'),

                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),

                        TextInput::make('sort')->label('Порядок')->numeric()->default(0),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('sku')->label('Артикул')->searchable(),

                TextColumn::make('option')
                    ->label('Вариант')
                    ->state(fn (ProductVariant $record) => $record->optionLabel()),

                TextColumn::make('price')
                    ->label('Цена')
                    ->state(fn (ProductVariant $record) => Money::rub($record->price)),

                TextColumn::make('available')
                    ->label('Доступно')
                    ->state(fn (ProductVariant $record) => $record->available())
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),

                IconColumn::make('is_default')->label('По умолчанию')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
