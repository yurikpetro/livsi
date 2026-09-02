<?php

namespace Tests\Unit;

use App\Support\Plural;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Свой склонятель появился потому, что `trans_choice()` с инлайновой строкой
 * не применяет правила русского языка: он отдавал «5 товара», «21 товара»
 * и «101 товара». Эти числа и проверяем.
 */
class PluralTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_russian_plural_forms(int $number, string $expected): void
    {
        $this->assertSame($expected, Plural::products($number));
    }

    public static function numbers(): array
    {
        return [
            'ноль'                  => [0, '0 товаров'],
            'один'                  => [1, '1 товар'],
            'два'                   => [2, '2 товара'],
            'четыре'                => [4, '4 товара'],
            'пять'                  => [5, '5 товаров'],
            'восемь'                => [8, '8 товаров'],
            'одиннадцать'           => [11, '11 товаров'],
            'двенадцать'            => [12, '12 товаров'],
            'четырнадцать'          => [14, '14 товаров'],
            'пятнадцать'            => [15, '15 товаров'],
            'двадцать один'         => [21, '21 товар'],
            'двадцать два'          => [22, '22 товара'],
            'двадцать пять'         => [25, '25 товаров'],
            'сто один'              => [101, '101 товар'],
            'сто одиннадцать'       => [111, '111 товаров'],
            'сто двадцать два'      => [122, '122 товара'],
            'тысяча'                => [1000, '1000 товаров'],
        ];
    }

    public function test_reviews_use_their_own_forms(): void
    {
        $this->assertSame('1 отзыв', Plural::reviews(1));
        $this->assertSame('326 отзывов', Plural::reviews(326));
        $this->assertSame('392 отзыва', Plural::reviews(392));
    }
}
