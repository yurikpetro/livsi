<?php

namespace App\Filament\Resources\StockQuotas\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StockQuotaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('product_variant_id')
                    ->required()
                    ->numeric(),
                TextInput::make('allocated')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('reserved')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('sold')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('low_threshold')
                    ->required()
                    ->numeric()
                    ->default(5),
                DateTimePicker::make('allocated_at'),
            ]);
    }
}
