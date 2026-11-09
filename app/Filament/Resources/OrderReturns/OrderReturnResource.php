<?php

namespace App\Filament\Resources\OrderReturns;

use App\Filament\Resources\OrderReturns\Pages\ListOrderReturns;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderReturn;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Заявки на возврат — список. Работа с заявкой — в карточке заказа:
 * там же деньги, доставка и журнал.
 */
class OrderReturnResource extends Resource
{
    protected static ?string $model = OrderReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Возвраты';

    protected static ?string $modelLabel = 'возврат';

    protected static ?string $pluralModelLabel = 'Возвраты';

    public static function canCreate(): bool
    {
        return false;
    }

    /** Сколько заявок ждут решения — в меню. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = OrderReturn::query()->where('status', OrderReturn::REQUESTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('order'))
            ->recordUrl(fn (OrderReturn $record): ?string => $record->order ? OrderResource::getUrl('edit', ['record' => $record->order]) : null)
            ->columns([
                TextColumn::make('created_at')->label('Заявка')->dateTime('d.m.Y H:i', 'Europe/Moscow')->sortable(),
                TextColumn::make('order.number')->label('Заказ')->searchable(),
                TextColumn::make('order.customer_name')->label('Покупатель')->searchable(),
                TextColumn::make('reason')->label('Причина')->formatStateUsing(fn (string $state): string => OrderReturn::reasons()[$state] ?? $state),
                TextColumn::make('goods_amount')->label('Товар')->formatStateUsing(fn ($state): string => Money::rub((int) $state)),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => OrderReturn::statuses()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        OrderReturn::REQUESTED => 'warning',
                        OrderReturn::APPROVED, OrderReturn::RECEIVED => 'info',
                        OrderReturn::REFUNDED => 'success',
                        OrderReturn::REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('shipment_number')->label('СДЭК')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(OrderReturn::statuses()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderReturns::route('/'),
        ];
    }
}
