<?php

namespace App\Filament\Resources\Declarations\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class DeclarationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label('Номер декларации')
                    ->required()
                    ->maxLength(255),

                TextInput::make('title')
                    ->label('На что выдана')
                    ->maxLength(255),

                DatePicker::make('issued_on')->label('Выдана')->native(false),
                DatePicker::make('valid_until')->label('Действует до')->native(false),

                // Одна декларация обычно покрывает несколько товаров.
                Select::make('products')
                    ->label('Товары')
                    ->relationship('products', 'title')
                    ->multiple()
                    ->preload()
                    ->searchable(),

                FileUpload::make('file_path')
                    ->label('Файл PDF')
                    ->disk('public')
                    ->directory('declarations')
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(20480)
                    ->downloadable()
                    ->openable(),

                TextInput::make('sort')->label('Порядок')->numeric()->default(0),
                Toggle::make('is_active')->label('Активна')->default(true),
            ]);
    }
}
