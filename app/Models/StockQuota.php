<?php

namespace App\Models;

use App\Monitoring\BusinessNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockQuota extends Model
{
    protected $guarded = [];

    protected $casts = [
        'allocated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updated(function (StockQuota $quota): void {
            $before = max(0, (int) $quota->getOriginal('allocated')
                - (int) $quota->getOriginal('reserved')
                - (int) $quota->getOriginal('sold'));
            $previousThreshold = (int) $quota->getOriginal('low_threshold');
            $available = $quota->available();

            if ($before > 0 && $available === 0) {
                app(BusinessNotifications::class)->queue('stock_empty', $quota->id, 'stock');
            } elseif ($before > $previousThreshold && $available > 0 && $available <= $quota->low_threshold) {
                app(BusinessNotifications::class)->queue('stock_low', $quota->id, 'stock');
            }
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockQuotaMovement::class);
    }

    public function available(): int
    {
        return max(0, $this->allocated - $this->reserved - $this->sold);
    }

    public function isLow(): bool
    {
        return $this->available() <= $this->low_threshold;
    }
}
