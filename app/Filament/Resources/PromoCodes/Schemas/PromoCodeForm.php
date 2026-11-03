<?php

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Models\Customer;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use App\Support\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PromoCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Промокод')->schema([
                TextInput::make('code')->label('Код')->required()->maxLength(40)
                    ->default(fn (): string => 'LIVSI-'.Str::upper(Str::random(6)))
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state): string => PromoCodeService::normalize($state))
                    ->helperText('Покупатель вводит его при оформлении. Регистр и пробелы не важны.'),
                Select::make('kind')->label('Что даёт')->options(PromoCode::kinds())->default(PromoCode::PERCENT)
                    ->required()->live()->selectablePlaceholder(false),
                TextInput::make('value')
                    ->label(fn (Get $get): string => $get('kind') === PromoCode::FIXED ? 'Скидка, ₽' : 'Скидка, %')
                    ->numeric()->minValue(1)->maxValue(fn (Get $get): ?int => $get('kind') === PromoCode::PERCENT ? 99 : null)
                    ->required(fn (Get $get): bool => $get('kind') !== PromoCode::FREE_DELIVERY)
                    ->visible(fn (Get $get): bool => $get('kind') !== PromoCode::FREE_DELIVERY),
                TextInput::make('min_total')->label('На заказ от, ₽')->numeric()->minValue(0)->helperText('Пусто — на любую сумму.'),
                DateTimePicker::make('expires_at')->label('Действует до')->seconds(false)->helperText('Пусто — бессрочно.'),
                TextInput::make('max_uses')->label('Сколько раз можно использовать')->numeric()->minValue(1)
                    ->helperText('Пусто — без ограничения. Отменённые и возвращённые заказы код не тратят.'),
                Toggle::make('once_per_customer')->label('Один раз на покупателя')->default(true),
                Select::make('customer_id')->label('Личный код клиента')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                        ->where(fn ($q) => $q->where('email', 'like', '%'.mb_strtolower($search).'%')->orWhere('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                        ->limit(20)->get()->mapWithKeys(fn (Customer $c): array => [$c->id => trim(($c->name ?: '').' · '.$c->email, ' ·')])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($c = Customer::find($value)) ? trim(($c->name ?: '').' · '.$c->email, ' ·') : null)
                    ->helperText('Пусто — общий код для всех. Личный подходит только этому клиенту и виден ему в кабинете.'),
                TextInput::make('note')->label('Для себя: за что, для какой акции')->maxLength(255),
                Toggle::make('active')->label('Действует')->default(true),
            ])->columns(2),
        ]);
    }

    /** Из базы в форму: рубли — рублями. */
    public static function toForm(array $data): array
    {
        if (($data['kind'] ?? null) === PromoCode::FIXED && isset($data['value'])) {
            $data['value'] = Money::toRubles((int) $data['value']);
        }
        if (isset($data['min_total'])) {
            $data['min_total'] = Money::toRubles((int) $data['min_total']);
        }

        return $data;
    }

    /** Из формы в базу: рубли → копейки. */
    public static function toModel(array $data): array
    {
        $kind = $data['kind'] ?? PromoCode::PERCENT;
        $value = (float) str_replace([' ', ','], ['', '.'], (string) ($data['value'] ?? 0));
        $data['value'] = match ($kind) {
            PromoCode::FIXED => (int) round($value * 100),
            PromoCode::PERCENT => (int) $value,
            default => 0,
        };
        $data['min_total'] = filled($data['min_total'] ?? null) ? Money::toKopecks((string) $data['min_total']) : null;

        return $data;
    }
}
