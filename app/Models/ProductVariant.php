<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quota(): HasOne
    {
        return $this->hasOne(StockQuota::class);
    }

    /** Доступно к продаже с сайта: выделенная квота минус резервы и продажи. */
    public function available(): int
    {
        $quota = $this->quota;

        if (! $quota) {
            return 0;
        }

        return max(0, $quota->allocated - $quota->reserved - $quota->sold);
    }

    /** Название варианта: «200 мл · сочная вишня». Используется в админке. */
    public function optionLabel(): string
    {
        return collect([$this->option_volume, $this->option_aroma])
            ->filter()
            ->implode(' · ');
    }

    /**
     * Подпись для витрины. Если аромат не вынесен в ось варианта, берём его
     * с товара: покупателю важен аромат, а не то, как он смоделирован.
     */
    public function storefrontLabel(): string
    {
        return collect([$this->option_volume, $this->option_aroma ?: $this->product?->aroma])
            ->filter()
            ->implode(' · ');
    }
}
