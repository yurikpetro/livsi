<?php

namespace App\Filament\Resources\SellerProfiles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SellerProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('legal_name')
                    ->required(),
                TextInput::make('inn'),
                TextInput::make('kpp'),
                TextInput::make('ogrn'),
                TextInput::make('address'),
                TextInput::make('bank_name'),
                TextInput::make('bank_bic'),
                TextInput::make('bank_account'),
                TextInput::make('bank_corr_account'),
                TextInput::make('signer_name'),
                TextInput::make('signer_position'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                Toggle::make('is_default')
                    ->required(),
            ]);
    }
}
