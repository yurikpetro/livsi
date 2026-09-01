<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('short_description'),
                TextInput::make('badge'),
                TextInput::make('product_line_id')
                    ->numeric(),
                Toggle::make('is_pro')
                    ->required(),
                Toggle::make('is_bundle')
                    ->required(),
                TextInput::make('aroma'),
                TextInput::make('effect'),
                Textarea::make('composition')
                    ->columnSpanFull(),
                Textarea::make('application')
                    ->columnSpanFull(),
                Textarea::make('active_ingredients')
                    ->columnSpanFull(),
                TextInput::make('shelf_life'),
                Textarea::make('documents')
                    ->columnSpanFull(),
                TextInput::make('rating')
                    ->numeric(),
                TextInput::make('reviews_count')
                    ->numeric(),
                TextInput::make('reviews_source'),
                TextInput::make('vat_rate')
                    ->numeric(),
                TextInput::make('seo_title'),
                TextInput::make('seo_description'),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
                DateTimePicker::make('published_at'),
            ]);
    }
}
