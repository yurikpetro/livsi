<?php

namespace App\Filament\Resources\SellerProfiles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Реквизиты продавца. Попадают в чек, оферту, письма и на юридические
 * страницы, поэтому это отдельная сущность, а не константы в коде.
 *
 * Форматы ИНН, БИК и счёта проверяются по длине: ошибка в реквизитах
 * обнаружится только на первом платеже, и это дорого.
 */
class SellerProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Организация')
                    ->schema([
                        TextInput::make('code')
                            ->label('Код профиля')
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->alphaDash()
                            ->helperText('Служебный, для ссылок из кода. Например: main.'),

                        TextInput::make('legal_name')
                            ->label('Полное наименование')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('ИП Волкодав Антон Андреевич')
                            ->columnSpan(2),

                        TextInput::make('inn')
                            ->label('ИНН')
                            ->numeric()
                            ->minLength(10)
                            ->maxLength(12)
                            ->helperText('10 цифр у организации, 12 у ИП.'),

                        TextInput::make('kpp')
                            ->label('КПП')
                            ->numeric()
                            ->maxLength(9)
                            ->helperText('У ИП отсутствует.'),

                        TextInput::make('ogrn')
                            ->label('ОГРН / ОГРНИП')
                            ->numeric()
                            ->minLength(13)
                            ->maxLength(15),

                        TextInput::make('address')
                            ->label('Юридический адрес')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Банковские реквизиты')
                    ->schema([
                        TextInput::make('bank_name')->label('Банк')->maxLength(255)->columnSpan(2),
                        TextInput::make('bank_bic')
                            ->label('БИК')
                            ->numeric()
                            ->minLength(9)
                            ->maxLength(9),
                        TextInput::make('bank_account')
                            ->label('Расчётный счёт')
                            ->numeric()
                            ->minLength(20)
                            ->maxLength(20),
                        TextInput::make('bank_corr_account')
                            ->label('Корреспондентский счёт')
                            ->numeric()
                            ->minLength(20)
                            ->maxLength(20),
                    ])
                    ->columns(3)
                    ->collapsed(),

                Section::make('Подписант и связь')
                    ->schema([
                        TextInput::make('signer_name')->label('ФИО подписанта')->maxLength(255),
                        TextInput::make('signer_position')->label('Должность подписанта')->maxLength(255),
                        TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                        TextInput::make('phone')->label('Телефон')->tel()->maxLength(64),

                        Toggle::make('is_default')
                            ->label('Основной профиль')
                            ->helperText('Его реквизиты подставляются в документы и чеки.'),
                    ])
                    ->columns(2),
            ]);
    }
}
