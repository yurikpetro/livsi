<?php

namespace App\Support;

/**
 * Деньги в проекте хранятся целыми копейками. Ни одного float в расчётах.
 */
final class Money
{
    /** «490 ₽» — с неразрывным пробелом перед знаком рубля. */
    public static function rub(?int $kopecks): string
    {
        if ($kopecks === null) {
            return '—';
        }

        $rubles = intdiv($kopecks, 100);
        $cents  = $kopecks % 100;

        $formatted = number_format($rubles, 0, ',', ' ');

        if ($cents > 0) {
            $formatted .= ',' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }

        return $formatted . "\u{00A0}₽";
    }

    /**
     * Рубли из копеек для полей ввода: «990.5».
     *
     * Админка показывает рубли, база хранит копейки. Без этой пары человек
     * вводит 990 и получает товар за 9 рублей 90 копеек.
     */
    public static function toRubles(?int $kopecks): ?string
    {
        if ($kopecks === null) {
            return null;
        }

        return rtrim(rtrim(number_format($kopecks / 100, 2, '.', ''), '0'), '.');
    }

    /** Копейки из введённых рублей. Принимает «1 990,50», «1990.5», «1990». */
    public static function toKopecks(mixed $rubles): ?int
    {
        if ($rubles === null || $rubles === '') {
            return null;
        }

        $normalized = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], (string) $rubles);

        if (! is_numeric($normalized)) {
            return null;
        }

        // round, а не приведение к int: 19.99 * 100 в двоичной арифметике
        // даёт 1998.9999, и копейка потерялась бы.
        return (int) round((float) $normalized * 100);
    }
}
