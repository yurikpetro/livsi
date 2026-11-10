<?php

namespace App\Filament\Resources\SeoRedirects;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Resources\MissingPages\MissingPageResource;
use App\Filament\Resources\SeoRedirects\Pages\ManageSeoRedirects;
use App\Models\SeoRedirect;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** «Переадресации»: старый адрес → новая страница (301). Меняются без программиста. */
class SeoRedirectResource extends Resource
{
    protected static ?string $model = SeoRedirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = AnalyticsPage::GROUP;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Переадресации';

    protected static ?string $modelLabel = 'переадресация';

    protected static ?string $pluralModelLabel = 'Переадресации';

    protected static ?string $slug = 'analytics/redirects';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')->label('Старый адрес')->required()->maxLength(300)
                ->helperText('Путь на этом сайте, например /shampuni или /magazin/vendor/livsi-professional. Можно вставить ссылку целиком.')
                ->dehydrateStateUsing(fn ($state) => SeoRedirect::normalize((string) $state))
                ->rule(fn (?SeoRedirect $record) => function (string $attribute, $value, $fail) use ($record): void {
                    $path = SeoRedirect::normalize((string) $value);
                    if ($path === '/') {
                        $fail('Главную страницу перенаправлять нельзя.');
                    }
                    if (SeoRedirect::query()->where('from_path', $path)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                        $fail('Для этого адреса переадресация уже есть.');
                    }
                }),
            TextInput::make('to_url')->label('Куда вести')->required()->maxLength(500)
                ->helperText('Страница на этом сайте (/catalog, /product/…) или полный адрес.')
                ->dehydrateStateUsing(fn ($state) => MissingPageResource::target((string) $state)),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('from_path')->label('Старый адрес')->searchable()->wrap(),
                TextColumn::make('to_url')->label('Куда ведёт')->searchable()->wrap()
                    ->url(fn (SeoRedirect $record): string => url($record->to_url), shouldOpenInNewTab: true),
                TextColumn::make('hits')->label('Переходов')->numeric()->sortable(),
                TextColumn::make('last_hit_at')->label('Последний')->dateTime('d.m.Y H:i', 'Europe/Moscow')->placeholder('—')->sortable(),
            ])
            ->recordActions([
                EditAction::make()->label('Изменить'),
                DeleteAction::make()->label('Удалить'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSeoRedirects::route('/')];
    }
}
