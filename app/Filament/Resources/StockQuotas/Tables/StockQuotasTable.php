<?php

namespace App\Filament\Resources\StockQuotas\Tables;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use App\Models\ProductVariant;
use App\Models\StockQuota;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Рабочий экран менеджера по варианту В: сайт торгует выделенной квотой,
 * маркетплейсы — своей частью склада (docs/06-scope-v2.md §2.1).
 *
 * Здесь видно, сколько выделено, сколько зарезервировано под неоплаченные
 * заказы, сколько продано и сколько реально осталось.
 */
class StockQuotasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(ProductVariant::query()->with(['product.images', 'quota']))
            ->recordUrl(null)
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('product_image')
                    ->label('Фото')
                    ->disk('public_images')
                    ->visibility('public')
                    ->state(fn (ProductVariant $record): ?string => $record->product?->images->first()?->path)
                    ->imageSize(40)
                    ->square(),

                TextColumn::make('product.title')
                    ->label('Товар')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('option_volume')
                    ->label('Вариант')
                    ->state(fn (ProductVariant $record): string => $record->optionLabel() ?: '—')
                    ->searchable(['option_volume', 'option_aroma']),

                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable(),

                TextColumn::make('quota.allocated')
                    ->label('Выделено')
                    ->default(0)
                    ->numeric()
                    ->sortable(),

                TextColumn::make('quota.reserved')
                    ->label('В резерве')
                    ->default(0)
                    ->numeric()
                    ->sortable(),

                TextColumn::make('quota.sold')
                    ->label('Продано')
                    ->default(0)
                    ->numeric()
                    ->sortable(),

                // Доступно = выделено − резерв − продано.
                TextColumn::make('available')
                    ->label('Доступно')
                    ->state(fn (ProductVariant $record): int => $record->available())
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match (true) {
                        $record->available() <= 0 => 'danger',
                        $record->quota?->isLow() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('quota.low_threshold')
                    ->label('Порог')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('quota.allocated_at')
                    ->label('Пополнено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('product_active')
                    ->label('Активность товара')
                    ->placeholder('Все товары')
                    ->trueLabel('Активные товары')
                    ->falseLabel('Неактивные товары')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('is_active', true)),
                        false: fn (Builder $query): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('is_active', false)),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('low')
                    ->label('Заканчивается')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereDoesntHave('quota')
                        ->orWhereHas('quota', fn (Builder $quota): Builder => $quota->whereRaw('allocated - reserved - sold <= low_threshold')))),

                Filter::make('empty')
                    ->label('Нет в наличии')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereDoesntHave('quota')
                        ->orWhereHas('quota', fn (Builder $quota): Builder => $quota->whereRaw('allocated - reserved - sold <= 0')))),
            ])
            ->deferFilters(false)
            ->recordActions([
                // Пополнение квоты — самое частое действие, поэтому отдельной кнопкой,
                // а не через форму редактирования.
                Action::make('replenish')
                    ->label('Пополнить')
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        TextInput::make('qty')
                            ->label('Добавить к выделенному, шт.')
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('comment')
                            ->label('Комментарий')
                            ->maxLength(255),
                    ])
                    ->action(function (ProductVariant $record, array $data): void {
                        $quota = DB::transaction(function () use ($record, $data): StockQuota {
                            $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                            $quota = StockQuota::firstOrCreate(['product_variant_id' => $record->id]);
                            $quota = $quota->newQuery()->whereKey($quota->id)->lockForUpdate()->firstOrFail();
                            $quota->increment('allocated', (int) $data['qty']);
                            $quota->forceFill(['allocated_at' => now()])->save();
                            $quota->movements()->create([
                                'type' => 'allocate',
                                'qty' => (int) $data['qty'],
                                'comment' => $data['comment'] ?? null,
                                'user_id' => auth()->id(),
                            ]);

                            return $quota;
                        });

                        Notification::make()
                            ->title('Квота пополнена')
                            ->body('Доступно к продаже: '.$quota->fresh()->available())
                            ->success()
                            ->send();
                    }),

                Action::make('edit')
                    ->label('Изменить')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (ProductVariant $record): string => $record->quota
                        ? StockQuotaResource::getUrl('edit', ['record' => $record->quota])
                        : StockQuotaResource::getUrl('create', ['variant' => $record->id])),
            ]);
    }
}
