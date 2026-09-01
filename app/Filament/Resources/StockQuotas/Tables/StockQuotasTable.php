<?php

namespace App\Filament\Resources\StockQuotas\Tables;

use App\Models\StockQuota;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ->defaultSort('id')
            ->columns([
                TextColumn::make('variant.product.title')
                    ->label('Товар')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('variant.option_volume')
                    ->label('Вариант')
                    ->formatStateUsing(fn ($record) => $record->variant?->optionLabel() ?: '—')
                    ->searchable(),

                TextColumn::make('variant.sku')
                    ->label('Артикул')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('allocated')
                    ->label('Выделено')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('reserved')
                    ->label('В резерве')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('sold')
                    ->label('Продано')
                    ->numeric()
                    ->sortable(),

                // Доступно = выделено − резерв − продано.
                TextColumn::make('available')
                    ->label('Доступно')
                    ->state(fn (StockQuota $record) => $record->available())
                    ->badge()
                    ->color(fn (StockQuota $record) => match (true) {
                        $record->available() <= 0        => 'danger',
                        $record->isLow()                 => 'warning',
                        default                          => 'success',
                    }),

                TextColumn::make('low_threshold')
                    ->label('Порог')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('allocated_at')
                    ->label('Пополнено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('low')
                    ->label('Заканчивается')
                    ->query(fn (Builder $query) => $query->whereRaw('allocated - reserved - sold <= low_threshold')),

                Filter::make('empty')
                    ->label('Нет в наличии')
                    ->query(fn (Builder $query) => $query->whereRaw('allocated - reserved - sold <= 0')),
            ])
            ->recordActions([
                // Пополнение квоты — самое частое действие, поэтому отдельной кнопкой,
                // а не через форму редактирования.
                Action::make('replenish')
                    ->label('Пополнить')
                    ->icon('heroicon-o-plus-circle')
                    ->schema([
                        TextInput::make('qty')
                            ->label('Добавить к выделенному, шт.')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('comment')
                            ->label('Комментарий')
                            ->maxLength(255),
                    ])
                    ->action(function (StockQuota $record, array $data): void {
                        $record->increment('allocated', (int) $data['qty']);
                        $record->forceFill(['allocated_at' => now()])->save();

                        $record->movements()->create([
                            'type'    => 'allocate',
                            'qty'     => (int) $data['qty'],
                            'comment' => $data['comment'] ?? null,
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Квота пополнена')
                            ->body('Доступно к продаже: ' . $record->fresh()->available())
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ]);
    }
}
