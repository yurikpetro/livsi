<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал смены статуса доставки: кто, когда и откуда (вручную или из API).
 * Записи не правятся и не удаляются — это история заказа.
 */
class OrderFulfillmentEvent extends Model
{
    protected $guarded = [];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_APISHIP = 'apiship';

    /** Сборщик на livsipro.ru: кнопки «Собран», «Передан в доставку». */
    public const SOURCE_LIVSIPRO = 'livsipro';

    public const SOURCE_CDEK = 'cdek';

    public const SOURCE_YANDEX = 'yandex';

    public const SOURCE_OZON = 'ozon';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_APISHIP => 'ApiShip',
            self::SOURCE_LIVSIPRO => 'livsipro.ru',
            self::SOURCE_CDEK => 'СДЭК',
            self::SOURCE_YANDEX => 'Яндекс Доставка',
            self::SOURCE_OZON => 'Ozon Доставка',
            default => $this->user?->name ?? 'Администратор',
        };
    }
}
