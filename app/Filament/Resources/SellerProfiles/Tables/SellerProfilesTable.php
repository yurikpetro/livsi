<?php

namespace App\Filament\Resources\SellerProfiles\Tables;

use App\Models\SellerProfile;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Профилей продавца обычно один-два, поэтому в списке только то, что
 * помогает их различить. Сгенерированная таблица показывала все
 * четырнадцать колонок, включая расчётный и корреспондентский счёт,
 * и уезжала по горизонтали.
 */
class SellerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')
                    ->label('Наименование')
                    ->searchable()
                    ->wrap()
                    ->description(fn (SellerProfile $record) => $record->code),

                TextColumn::make('inn')
                    ->label('ИНН')
                    ->searchable()
                    ->placeholder('не заполнен'),

                TextColumn::make('bank_name')
                    ->label('Банк')
                    ->placeholder('не заполнен')
                    ->wrap(),

                // Полноту видно сразу: неполные реквизиты нельзя ставить в чек.
                TextColumn::make('filled')
                    ->label('Реквизиты')
                    ->badge()
                    ->state(fn (SellerProfile $record) => self::missing($record) === []
                        ? 'заполнены'
                        : 'нет: ' . implode(', ', self::missing($record)))
                    ->color(fn (SellerProfile $record) => self::missing($record) === [] ? 'success' : 'warning'),

                IconColumn::make('is_default')->label('Основной')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** @return array<int, string> */
    private static function missing(SellerProfile $record): array
    {
        $required = [
            'inn'          => 'ИНН',
            'ogrn'         => 'ОГРН',
            'address'      => 'адрес',
            'bank_bic'     => 'БИК',
            'bank_account' => 'счёт',
        ];

        return array_values(array_filter(
            $required,
            fn (string $label, string $field) => blank($record->{$field}),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
