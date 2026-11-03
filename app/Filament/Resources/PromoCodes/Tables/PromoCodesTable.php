<?php

namespace App\Filament\Resources\PromoCodes\Tables;

use App\Models\PromoCode;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PromoCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'liveOrders'])->withCount('liveOrders'))
            ->columns([
                TextColumn::make('code')->label('Код')->searchable()->copyable()->weight('bold'),
                TextColumn::make('benefit')->label('Что даёт')->state(fn (PromoCode $record): string => $record->benefit()),
                TextColumn::make('customer.name')->label('Кому')
                    ->state(fn (PromoCode $record): string => $record->customer ? ($record->customer->name ?: $record->customer->email) : 'всем')
                    ->description(fn (PromoCode $record): ?string => $record->customer?->email),
                TextColumn::make('uses')->label('Использован')
                    ->state(fn (PromoCode $record): string => $record->live_orders_count.($record->max_uses ? ' из '.$record->max_uses : ''))
                    ->description(fn (PromoCode $record): ?string => $record->liveOrders->pluck('number')->take(3)->implode(', ') ?: null),
                TextColumn::make('state')->label('Состояние')->badge()->state(fn (PromoCode $record): string => $record->stateLabel())
                    ->color(fn (string $state): string => $state === 'действует' ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label('До')->placeholder('бессрочно')->sortable()
                    ->state(fn (PromoCode $record): ?string => $record->expires_at?->timezone('Europe/Moscow')->format('d.m.Y')),
                TextColumn::make('issued_by')->label('Выдал')->placeholder('—')->toggleable()
                    ->description(fn (PromoCode $record): ?string => $record->note),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Что даёт')->options(PromoCode::kinds()),
                TernaryFilter::make('personal')->label('Чей')->trueLabel('Личные')->falseLabel('Общие')
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('customer_id'), false: fn (Builder $q) => $q->whereNull('customer_id')),
                TernaryFilter::make('active')->label('Включён'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
