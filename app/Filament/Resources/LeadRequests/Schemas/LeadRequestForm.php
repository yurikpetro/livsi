<?php

namespace App\Filament\Resources\LeadRequests\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class LeadRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('contact')
                    ->required(),
                TextInput::make('city'),
                TextInput::make('sales_format'),
                TextInput::make('product_category'),
                TextInput::make('planned_volume'),
                Textarea::make('comment')
                    ->columnSpanFull(),
                DateTimePicker::make('consent_at')
                    ->required(),
                TextInput::make('ip'),
                TextInput::make('user_agent'),
                Textarea::make('utm')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('new'),
                Textarea::make('manager_comment')
                    ->columnSpanFull(),
            ]);
    }
}
