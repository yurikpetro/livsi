<?php

namespace App\Services;

class DeliverySelection
{
    public const PICKUP_POINT = 'pickup_point';

    public const CDEK_POINT = 'cdek_point';

    public const YANDEX_POINT = 'yandex_point';

    public const OZON_POINT = 'ozon_point';

    public const STAVROPOL_COURIER = 'stavropol_courier';

    public static function methods(): array
    {
        return [
            self::PICKUP_POINT => 'Пункт выдачи',
            self::CDEK_POINT => 'СДЭК · пункт выдачи',
            self::YANDEX_POINT => 'Яндекс Доставка · пункт выдачи',
            self::OZON_POINT => 'Ozon Доставка · пункт выдачи',
            self::STAVROPOL_COURIER => 'Курьер по Ставрополю',
        ];
    }

    public static function label(?string $method): string
    {
        return self::methods()[$method] ?? ($method ?: 'Не выбрана');
    }

    public static function providerKey(?string $method): ?string
    {
        return match ($method) {
            self::CDEK_POINT => 'cdek',
            self::YANDEX_POINT => 'yataxi',
            self::OZON_POINT => 'ozonlog',
            default => null,
        };
    }

    public static function pickupMethods(): array
    {
        return [self::CDEK_POINT, self::YANDEX_POINT, self::OZON_POINT];
    }

    public static function isStavropol(string $city): bool
    {
        return mb_strtolower(trim($city)) === mb_strtolower((string) config('livsi.delivery.origin_city'));
    }
}
