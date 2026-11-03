<?php

use App\Delivery\DeliverySettings;
use App\Models\DeliveryPoint;
use Illuminate\Database\Migrations\Migration;

/**
 * Два пункта Яндекса в Ставрополе, куда служба считает 918 ₽ и 9 дней, когда
 * в остальные 72 пункта города — 158 ₽ и 3 дня (проверено 03.11.2026). Прячем
 * их с карты; список потом правится в «Интеграциях доставки».
 */
return new class extends Migration
{
    private const ADDRESSES = ['%Дениса Зорина ул 35', '%Авангардная ул 6а'];

    public function up(): void
    {
        $ids = DeliveryPoint::query()->where('provider', 'yataxi')
            ->where(fn ($query) => collect(self::ADDRESSES)->each(fn (string $like) => $query->orWhere('address', 'like', $like)))
            ->pluck('external_id')->all();
        if ($ids) {
            DeliverySettings::setHiddenPoints('yataxi', [...DeliverySettings::hiddenPoints('yataxi'), ...$ids]);
        }
    }

    public function down(): void
    {
        $ids = DeliveryPoint::query()->where('provider', 'yataxi')
            ->where(fn ($query) => collect(self::ADDRESSES)->each(fn (string $like) => $query->orWhere('address', 'like', $like)))
            ->pluck('external_id')->all();
        DeliverySettings::setHiddenPoints('yataxi', array_diff(DeliverySettings::hiddenPoints('yataxi'), $ids));
    }
};
