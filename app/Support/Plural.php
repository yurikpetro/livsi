<?php

namespace App\Support;

final class Plural
{
    /**
     * Русское склонение по числу.
     *
     * Своя реализация вместо `trans_choice()`: с инлайновой строкой вида
     * «:count товар|:count товара|:count товаров» Laravel не применяет
     * правила русского языка и отдаёт вторую форму почти для всего —
     * «5 товара», «21 товара», «101 товара». Проверено, см. PluralTest.
     *
     * Правило: 11–14 и всё, что оканчивается не на 1–4, — множественная форма.
     */
    public static function ru(int $number, string $one, string $few, string $many): string
    {
        $abs  = abs($number);
        $last = $abs % 10;
        $tens = $abs % 100;

        $form = match (true) {
            $tens >= 11 && $tens <= 14 => $many,
            $last === 1                => $one,
            $last >= 2 && $last <= 4   => $few,
            default                    => $many,
        };

        return $number . ' ' . $form;
    }

    /** «8 товаров» — самый частый случай на витрине. */
    public static function products(int $number): string
    {
        return self::ru($number, 'товар', 'товара', 'товаров');
    }

    /** «326 отзывов» */
    public static function reviews(int $number): string
    {
        return self::ru($number, 'отзыв', 'отзыва', 'отзывов');
    }
}
