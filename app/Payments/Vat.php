<?php

namespace App\Payments;

use App\Models\Setting;
use RuntimeException;

/**
 * Ставка НДС и её код для кассового чека.
 *
 * В чек уходит не процент, а код ставки: 1 — без НДС, 2 — 0 %, 3 — 10 %,
 * 4 — 20 %, 7 — 5 %, 8 — 7 %, 11 — 22 %. Коды назначает ФНС, и при
 * изменении законодательства появляются новые — так было со ставкой 22 %,
 * получившей код 11 только к 2026 году.
 *
 * Поэтому соответствие «ставка → код» лежит в настройках и правится
 * заказчиком по документации провайдера, а не зашито в коде: иначе каждая
 * правка налогового кодекса требовала бы разработчика и выката.
 *
 * Пустая ставка не подменяется нулём: ноль — это осознанная ставка 0 %,
 * а пусто означает «не выяснили». Отправить в чек угаданную ставку хуже,
 * чем не отправить платёж: чек придётся корректировать, а расхождение
 * всплывёт при проверке.
 */
final class Vat
{
    public const NONE = 1;

    /**
     * Ставки и их коды по умолчанию.
     *
     * С 1 января 2026 года (ст. 164 НК РФ в редакции ФЗ от 28.11.2025 № 425-ФЗ)
     * действуют: 22 % основная, 10 % льготная, 0 % экспортная, а также 5 % и 7 %
     * для УСН по порогам дохода.
     *
     * Коды взяты из документации ЮKassa. Порядок кодов не повторяет порядок
     * ставок: 5 % — это код 7, а 7 % — код 8, и перепутать их легко.
     *
     * Расчётных ставок (10/110, 20/120, 5/105, 7/107, 22/122) здесь нет:
     * они не выражаются процентом и применяются к авансам и агентским
     * операциям, которых у розничного магазина не бывает. Понадобятся —
     * это отдельная величина, а не строка в этой таблице.
     *
     * Если закон введёт ещё одну ставку, заказчик добавит её здесь же,
     * не дожидаясь выката. Строка без кода не работает намеренно: платёж
     * по такой ставке не создаётся, и сообщение прямо называет ставку.
     *
     * @var array<string, int|string>
     */
    public const DEFAULT_CODES = [
        '22' => 11,
        '20' => 4,
        '10' => 3,
        '7'  => 8,
        '5'  => 7,
        '0'  => 2,
    ];

    /** Продавец не является плательщиком НДС — весь чек идёт «без НДС». */
    public static function sellerIsNotPayer(): bool
    {
        return (bool) Setting::get('vat_not_payer', false);
    }

    /**
     * Соответствие ставки и кода из настроек.
     *
     * @return array<string, int>
     */
    public static function codes(): array
    {
        $stored = Setting::get('vat_codes');

        $source = is_array($stored) && $stored !== [] ? $stored : self::DEFAULT_CODES;

        $codes = self::clean($source);

        // Пустой список после очистки означает, что кодов не проставили
        // вовсе — тогда работают только заведомо известные.
        return $codes ?: self::clean(self::DEFAULT_CODES);
    }

    /**
     * @throws RuntimeException если ставка не определена или для неё нет кода
     */
    public static function code(?float $rate): int
    {
        if (self::sellerIsNotPayer()) {
            return self::NONE;
        }

        $rate ??= self::defaultRate();

        if ($rate === null) {
            throw new RuntimeException(
                'Не задана ставка НДС. Укажите её у товара или значением по умолчанию '
                . 'в настройках, либо отметьте, что продавец не платит НДС.',
            );
        }

        $key   = self::normalize($rate);
        $codes = self::codes();

        if (! isset($codes[$key])) {
            throw new RuntimeException(
                "Для ставки {$key} % не задан код для кассового чека. "
                . 'Добавьте его в настройках сайта — код берётся из документации ЮKassa.',
            );
        }

        return $codes[$key];
    }

    public static function defaultRate(): ?float
    {
        $value = Setting::get('vat_rate_default');

        return $value === null || $value === '' ? null : (float) $value;
    }

    /** Можно ли вообще формировать чеки — проверяется до создания платежа. */
    public static function isConfigured(): bool
    {
        if (self::sellerIsNotPayer()) {
            return true;
        }

        $rate = self::defaultRate();

        return $rate !== null && isset(self::codes()[self::normalize($rate)]);
    }

    /**
     * Убрать ставки без кода и привести ключи к одному виду.
     *
     * Пустой код — это «ещё не выяснили», и пропустить его нельзя:
     * приведение к целому дало бы ноль, а ноль — не код ставки.
     *
     * @param  array<string, int|string|null>  $source
     * @return array<string, int>
     */
    private static function clean(array $source): array
    {
        $codes = [];

        foreach ($source as $rate => $code) {
            if ($code === null || $code === '' || (int) $code < 1) {
                continue;
            }

            $codes[self::normalize((float) $rate)] = (int) $code;
        }

        return $codes;
    }

    /** «20.00» и «20» — одна и та же ставка. */
    private static function normalize(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
