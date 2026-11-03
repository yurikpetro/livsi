<?php

namespace App\Filament\Support;

use App\Models\PromoCode;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Поля «какой промокод выдать» — одни и те же в карточке клиента,
 * в массовой выдаче и в рассылке. Рубли человек вводит рублями.
 */
final class PromoFields
{
    /** @return array<int, mixed> */
    public static function make(string $prefix = ''): array
    {
        return [
            Select::make($prefix.'kind')->label('Что даёт промокод')->options(PromoCode::kinds())
                ->default(PromoCode::PERCENT)->required()->live()->selectablePlaceholder(false),
            TextInput::make($prefix.'value')
                ->label(fn (Get $get): string => $get($prefix.'kind') === PromoCode::FIXED ? 'Скидка, ₽' : 'Скидка, %')
                ->numeric()->minValue(1)->maxValue(fn (Get $get): ?int => $get($prefix.'kind') === PromoCode::PERCENT ? 99 : null)
                ->required(fn (Get $get): bool => $get($prefix.'kind') !== PromoCode::FREE_DELIVERY)
                ->visible(fn (Get $get): bool => $get($prefix.'kind') !== PromoCode::FREE_DELIVERY),
            TextInput::make($prefix.'min_total')->label('На заказ от, ₽')->numeric()->minValue(0)
                ->helperText('Пусто — на любую сумму.'),
            TextInput::make($prefix.'days')->label('Действует, дней')->numeric()->minValue(1)->maxValue(365)->default(30)
                ->helperText('Пусто — бессрочно.'),
        ];
    }

    /** Данные формы → спецификация для PromoCodeService::issue (рубли → копейки). */
    public static function spec(array $data, string $prefix = ''): array
    {
        $kind = (string) ($data[$prefix.'kind'] ?? PromoCode::PERCENT);
        $value = (float) ($data[$prefix.'value'] ?? 0);

        return [
            'kind' => $kind,
            'value' => match ($kind) {
                PromoCode::FIXED => (int) round($value * 100),
                PromoCode::PERCENT => (int) $value,
                default => 0,
            },
            'min_total' => filled($data[$prefix.'min_total'] ?? null) ? Money::toKopecks((string) $data[$prefix.'min_total']) : null,
            'days' => filled($data[$prefix.'days'] ?? null) ? (int) $data[$prefix.'days'] : null,
            'note' => $data[$prefix.'note'] ?? null,
        ];
    }
}
