<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockQuota extends Model
{
    protected $guarded = [];

    protected $casts = [
        'allocated_at' => 'datetime',
    ];

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
