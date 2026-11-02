<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductLine extends Model
{
    public const PROFESSIONAL_CODES = ['pro-skin', 'pro-manicure-pedicure'];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isProfessional(): bool
    {
        return in_array($this->code, self::PROFESSIONAL_CODES, true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
