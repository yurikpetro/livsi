<?php

namespace App\Filament\Resources\StockQuotas\Schemas;

use App\Models\ProductVariant;
use App\Models\StockQuota;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StockQuotaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_variant_id')
                    ->label('Товар и вариант')
                    ->relationship('variant', 'sku', modifyQueryUsing: fn (Builder $query, string $operation): Builder => $query
                        ->with('product')
                        ->when($operation === 'create', fn (Builder $query): Builder => $query->whereDoesntHave('quota')))
                    ->getOptionLabelFromRecordUsing(fn (ProductVariant $record): string => self::variantLabel($record))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => ProductVariant::query()
                        ->whereDoesntHave('quota')
                        ->with('product')
                        ->get()
                        ->filter(fn (ProductVariant $variant): bool => str_contains(mb_strtolower(self::variantLabel($variant)), mb_strtolower(trim($search))))
                        ->take(50)
                        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => self::variantLabel($variant)])
                        ->all())
                    ->preload()
                    ->default(fn (): ?int => request()->integer('variant') ?: null)
                    ->disabledOn('edit')
                    ->required()
                    ->exists('product_variants', 'id')
                    ->unique(ignoreRecord: true),
                TextInput::make('allocated')
                    ->label('Выделено сайту')
                    ->required()
                    ->integer()
                    ->minValue(fn (?StockQuota $record): int => $record ? $record->reserved + $record->sold : 0)
                    ->helperText('Общее выделенное количество, включая резерв и уже проданное. Для добавления остатков используйте «Пополнить» в списке.')
                    ->default(0),
                TextInput::make('reserved')
                    ->label('В резерве')
                    ->disabled()
                    ->dehydrated(false)
                    ->default(0),
                TextInput::make('sold')
                    ->label('Продано')
                    ->disabled()
                    ->dehydrated(false)
                    ->default(0),
                TextInput::make('low_threshold')
                    ->label('Порог уведомления')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(5),
            ]);
    }

    private static function variantLabel(ProductVariant $variant): string
    {
        return collect([$variant->product?->title, $variant->optionLabel(), $variant->sku])->filter()->implode(' · ');
    }
}
