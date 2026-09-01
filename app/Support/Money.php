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
}