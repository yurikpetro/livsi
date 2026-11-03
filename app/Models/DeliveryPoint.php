<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Пункт выдачи службы доставки — копия её списка, обновляется раз в сутки.
 */
class DeliveryPoint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'limits' => 'array',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * То, что видит карта на сайте. Формат тот же, что отдавал ApiShip,
     * поэтому страница оформления заказа не меняется.
     *
     * @return array<string, mixed>
     */
    public function toPublic(): array
    {
        return [
            'id' => $this->external_id,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'region' => $this->region,
            'provider' => $this->provider,
            'timetable' => $this->timetable,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
