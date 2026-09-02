<?php

namespace App\Support;

final class Text
{
    /**
     * Отбрасывает недопустимые байты в строке.
     *
     * Нужно на любом пользовательском вводе, который попадёт в поиск или
     * в состояние Livewire: на битом UTF-8 падает и `json_encode` при
     * сериализации снимка компонента, и `preg_*` с модификатором `/u`.
     * Такое приходит от ботов и от клиентов со сломанной кодировкой —
     * это не повод отдавать 500.
     */
    public static function utf8(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
