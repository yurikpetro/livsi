<?php

namespace App\Support;

/**
 * Российский номер к одному виду.
 *
 * Нормализация была написана дважды — в чеке и во входе через Яндекс, —
 * и оба раза по-своему. Телефон скоро станет ключом входа по коду из СМС,
 * а ключ, записанный в базу в двух разных форматах, не совпадёт сам с собой.
 *
 * Восьмёрка в начале приводится к семёрке: «8 900…» и «+7 900…» — это один
 * и тот же номер, и покупатель вводит то один, то другой.
 */
final class Phone
{
    /** Одиннадцать цифр без плюса: такой вид ждёт касса. */
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }

        return strlen($digits) === 11 && str_starts_with($digits, '7') ? $digits : null;
    }

    /** `+79001112233` — так храним у пользователя и показываем. */
    public static function format(?string $value): ?string
    {
        $digits = self::digits($value);

        return $digits ? '+' . $digits : null;
    }

    public static function isValid(?string $value): bool
    {
        return self::digits($value) !== null;
    }
}
