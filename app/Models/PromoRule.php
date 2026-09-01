<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PromoRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'   => 'array',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function gifts(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'promo_rule_gifts');
    }

    public function scopeLive($query)
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
