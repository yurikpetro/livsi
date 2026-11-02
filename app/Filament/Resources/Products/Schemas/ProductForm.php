<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\ProductVariants\Schemas\ProductVariantForm;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Карточка товара — главный экран, с которым работает заказчик.
 *
 * Внутренний артикул относится к товару; артикулы вариантов задаются отдельно.
 */
class ProductForm
{
    /** Бейджи подборок на карточке товара. */
    public const BADGES = [
        'new' => 'Новинка',
        'best' => 'Бестселлер',
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

                        TextInput::make('sku')
                            ->label('Артикул')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Для внутреннего учёта. На сайте не отображается.')
                            ->columnSpanFull(),

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

                Section::make('Варианты товара')
                    ->description('Добавляйте и редактируйте варианты здесь. Изменения сохраняются вместе с товаром.')
                    ->schema([
                        Repeater::make('variants')
                            ->hiddenLabel()
                            ->relationship()
                            ->schema(ProductVariantForm::components(inline: true))
                            ->defaultItems(0)
                            ->addActionLabel('Добавить вариант')
                            ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => collect([
                                $state['option_volume'] ?? null,
                                $state['option_aroma'] ?? null,
                                $state['sku'] ?? null,
                            ])->filter()->implode(' · ') ?: 'Новый вариант'),
                    ])
                    ->columnSpanFull(),

                Section::make('Навигация по каталогу')
                    ->description('Линейка и тип товара определяют его разделы в каталоге.')
                    ->schema([
                        Select::make('product_line_id')
                            ->label('Линейка')
                            ->relationship('line', 'title', fn (Builder $query): Builder => $query->orderBy('sort'))
                            ->searchable()
                            ->preload()
                            ->placeholder('без линейки')
                            ->helperText('У товара ровно одна линейка либо ни одной.'),

                        Toggle::make('is_bundle')
                            ->label('Набор')
                            ->helperText('Состав набора задаётся отдельно.'),

                    ])
                    ->columns(2),

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
                            ->label('Описание')
                            ->rows(3)
                            ->helperText('Подробное описание в карточке товара и быстром просмотре.')
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

                Section::make('Рекомендации')
                    ->schema([
                        Toggle::make('auto_recommendations')
                            ->label('Автоматический подбор рекомендаций')
                            ->default(true)
                            ->live()
                            ->helperText('Сначала товары той же линейки, затем остальные. Выключите, чтобы выбрать рекомендации вручную.'),

                        Select::make('recommended_product_ids')
                            ->label('Рекомендуемые товары')
                            ->multiple()
                            ->searchable()
                            ->allowHtml()
                            ->afterContent(view('filament.forms.product-recommendation-styles'))
                            ->placeholder('Название или артикул товара')
                            ->searchPrompt('Введите название или артикул')
                            ->searchDebounce(300)
                            ->options(fn (?Product $record): array => self::recommendationOptions($record))
                            ->getSearchResultsUsing(fn (string $search, ?Product $record): array => self::recommendationOptions($record, $search))
                            ->getOptionLabelsUsing(fn (array $values, ?Product $record): array => self::recommendationProductLabels($record, $values))
                            ->maxItems(3)
                            ->nestedRecursiveRules([
                                fn (?Product $record) => Rule::in(self::recommendationProducts($record)->pluck('id')->all()),
                            ])
                            ->visible(fn (Get $get): bool => ! $get('auto_recommendations'))
                            ->helperText('Начните вводить название или внутренний артикул и выберите товар. До трёх товаров в порядке выбора. Пустой список скрывает рекомендации.'),
                    ]),

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
                            ->maxValue(100)
                            ->step(0.01)
                            // Пустая ставка не равна нулю: нулевая ставка — отдельный
                            // осознанный случай, а пустое поле означает «не выяснили».
                            ->helperText('Пусто — берётся ставка по умолчанию из настроек. Ноль ставится осознанно: это ставка 0 %, а не «не выяснили».'),

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

    /** @return array<int, string> */
    private static function recommendationOptions(?Product $record, string $search = ''): array
    {
        $search = mb_strtolower(trim($search));

        return self::recommendationProducts($record)
            ->with('images')
            ->get(['id', 'title', 'sku'])
            ->filter(fn (Product $product): bool => $search === '' || str_contains(mb_strtolower($product->title.' '.$product->sku), $search))
            ->take(50)
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => view('filament.forms.product-recommendation-option', ['product' => $product])->render(),
            ])
            ->all();
    }

    /**
     * @param  array<int, int|string>  $values
     * @return array<int, string>
     */
    private static function recommendationProductLabels(?Product $record, array $values): array
    {
        return self::recommendationProducts($record)
            ->whereKey($values)
            ->get(['id', 'title', 'sku'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => e($product->title.($product->sku ? ' · '.$product->sku : '')),
            ])
            ->all();
    }

    /** @return Builder<Product> */
    private static function recommendationProducts(?Product $record): Builder
    {
        return Product::active()
            ->when($record, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->orderBy('sort')
            ->orderBy('id');
    }
}
