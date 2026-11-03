<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Support\CustomerActions;
use App\Models\Customer;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_order_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Клиент')->searchable(['name', 'email', 'phone'])
                    ->state(fn (Customer $record): string => $record->name ?: '—')
                    ->description(fn (Customer $record): ?string => $record->email),
                TextColumn::make('phone')->label('Телефон')->state(fn (Customer $record): ?string => $record->phone ? '+'.$record->phone : null)->placeholder('—'),
                TextColumn::make('city')->label('Город')->placeholder('—')->toggleable(),
                TextColumn::make('paid_orders_count')->label('Заказов')->sortable(),
                TextColumn::make('paid_total')->label('На сумму')->sortable()
                    ->state(fn (Customer $record): string => Money::rub((int) $record->paid_total)),
                TextColumn::make('last_order_at')->label('Последний заказ')->sortable()->placeholder('—')
                    ->state(fn (Customer $record): ?string => $record->last_order_at ? Carbon::parse($record->last_order_at)->timezone('Europe/Moscow')->format('d.m.Y') : null),
                TextColumn::make('consent')->label('Рассылка')->badge()
                    ->state(fn (Customer $record): string => $record->canReceiveMarketing() ? 'согласен' : ($record->unsubscribed_at ? 'отписался' : 'нет'))
                    ->color(fn (string $state): string => $state === 'согласен' ? 'success' : 'gray')
                    ->tooltip(fn (Customer $record): string => CustomerForm::consent($record)),
            ])
            ->filters([
                Filter::make('subscribed')->label('Согласны на рассылку')->query(fn (Builder $query) => $query->subscribed()),
                TernaryFilter::make('registered')->label('Аккаунт на сайте')
                    ->trueLabel('Зарегистрированы')->falseLabel('Покупали гостем')
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('user_id'), false: fn (Builder $q) => $q->whereNull('user_id')),
                Filter::make('buyers')->label('Есть оплаченные заказы')->query(fn (Builder $query) => $query->has('orders')->whereHas('orders', fn ($q) => $q->where('status', 'paid'))),
            ])
            ->recordActions([EditAction::make()->label('Открыть')])
            ->toolbarActions([
                BulkActionGroup::make([CustomerActions::issuePromoBulk(), CustomerActions::mailingBulk()])->label('С отмеченными'),
            ]);
    }
}
