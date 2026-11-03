<?php

namespace App\Delivery;

/**
 * Сравнение городов и регионов между службами.
 *
 * Каждая служба пишет по-своему: «г. Ставрополь» и «Ставрополь»,
 * «Ставропольский край» и «Ставропольский». Ключ — то общее, что остаётся
 * после снятия «г.», «край», «область» и регистра. Правила те же, что были
 * у сравнения пунктов ApiShip, чтобы выбор на карте вёл себя как раньше.
 */
final class PointNames
{
    public static function cityKey(string $city): string
    {
        $city = trim(preg_replace('/^(г\.?|город|пгт\.?|пос\.?|посёлок|поселок|с\.|село|ст-ца|станица)\s+/ui', '', trim($city)) ?? $city);

        return mb_strtolower(str_replace('ё', 'е', $city));
    }

    public static function regionKey(string $region): string
    {
        return mb_strtolower(str_replace('ё', 'е', self::regionName($region)));
    }

    public static function regionName(string $region): string
    {
        $region = trim($region);

        return trim(preg_replace('/^(республика)\s+|\s+(край|область|обл\.?|республика|автономный округ|автономная область|ао)$/ui', '', $region) ?? $region);
    }
}
