<?php

namespace App\Filament\Resources\PromoRules\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\ProductVariant;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Пороги бесплатной доставки и подарка.
 *
 * Что было не так в сгенерированной форме: порог показывался в копейках
 * (заказчик ввёл бы 5000 вместо 500000), тип и канал были свободным текстом,
 * а JSON-колонка `payload` — обычным текстовым полем; подарки при этом
 * хранятся в отдельной связи `promo_rule_gifts`, и выбрать их было нельзя.
 */
class PromoRuleForm
{
    public const TYPES = [
        'free_shipping' => 'Бесплатная доставка от суммы',
        'gift'          => 'Подарок от суммы',
    ];

    public const CHANNELS = [
        'retail'    => 'Розница',
        'wholesale' => 'Опт',
        'all'       => 'Везде',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Правило')
                    ->schema([
                        Select::make('type')
                            ->label('Тип')
                            ->options(self::TYPES)
                            ->required()
                            ->live()
                            ->selectablePlaceholder(false),

                        MoneyField::make('threshold', 'Порог суммы заказа')
                            ->required()
                            ->helperText('Сумма корзины, от которой правило срабатывает. В рублях.'),

                        Select::make('channel')
                            ->label('Где действует')
                            ->options(self::CHANNELS)
                            ->default('retail')
                            ->required()
                            ->selectablePlaceholder(false),

                        TextInput::make('title')
                            ->label('Название для себя')
                            ->maxLength(255)
                            ->placeholder('Подарок от 5 000 ₽')
                            ->helperText('Покупателю не показывается.'),
                    ])
                    ->columns(2),

                Section::make('Подарки на выбор')
                    ->description('Покупатель выбирает один из них при достижении порога. Подарок оформляется скидкой на заказ, а не позицией с нулевой ценой — иначе чек будет невалиден.')
                    ->visible(fn (Get $get) => $get('type') === 'gift')
                    ->schema([
                        Select::make('gifts')
                            ->label('Варианты товаров')
                            ->relationship('gifts', 'sku')
                            ->getOptionLabelFromRecordUsing(fn (ProductVariant $record) => $record->optionLabel() . ' · ' . $record->sku)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Обычно два-три варианта. Если не выбрать ни одного, подарок предложить будет нечего.'),
                    ]),

                Section::make('Срок действия')
                    ->description('Пустые даты означают «бессрочно».')
                    ->schema([
                        DateTimePicker::make('starts_at')->label('Начало')->seconds(false),
                        DateTimePicker::make('ends_at')->label('Окончание')->seconds(false)->after('starts_at'),
                        Toggle::make('is_active')->label('Активно')->default(true),
                        TextInput::make('sort')->label('Порядок')->numeric()->default(0),
                    ])
                    ->columns(2),
            ]);
    }
}
