<?php

namespace App\Filament\Support;

use App\Support\Money;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Денежное поле для админки: человек вводит рубли, в базу уходят копейки.
 *
 * Сгенерированные Filament поля показывали копейки как есть и с префиксом «$».
 * На таком поле заказчик вводит 990, ожидая 990 ₽, и получает товар
 * за 9 рублей 90 копеек — молча, без всякой ошибки.
 */
final class MoneyField
{
    public static function make(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? 'Цена')
            ->prefix('₽')
            // ->numeric() здесь нельзя: правило `numeric` не принимает
            // ни пробелов в разрядах, ни запятой, а «1 990,50» — это ровно
            // то, как сумму напишет человек.
            ->inputMode('decimal')
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                    if (filled($value) && Money::toKopecks($value) === null) {
                        $fail('Введите сумму в рублях: 990, 1 990,50 или 1990.50.');
                    }
                },
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                    if (filled($value) && Money::toKopecks($value) < 0) {
                        $fail('Сумма не может быть отрицательной.');
                    }
                },
            ])
            ->afterStateHydrated(fn (TextInput $component, ?int $state) => $component->state(Money::toRubles($state)))
            ->dehydrateStateUsing(fn (mixed $state) => Money::toKopecks($state))
            ->helperText('В рублях. Копейки — через точку или запятую.');
    }
}
