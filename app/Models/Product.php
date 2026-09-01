<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_pro'       => 'boolean',
        'is_bundle'    => 'boolean',
        'is_active'    => 'boolean',
        'rating'       => 'float',
        'vat_rate'     => 'float',
        'documents'    => 'array',
        'published_at' => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class, 'product_line_id');
    }

    public function purposes(): BelongsToMany
    {
        return $this->belongsToMany(Purpose::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_product_id');
    }

    public function defaultVariant(): ?ProductVariant
    {
        return $this->variants->firstWhere('is_default', true) ?? $this->variants->first();
    }

    /** Минимальная цена среди активных вариантов, в копейках. */
    public function priceFrom(): ?int
    {
        return $this->variants->where('is_active', true)->min('price');
    }

    /** Есть ли хоть один вариант, доступный к продаже из квоты сайта. */
    public function isAvailable(): bool
    {
        return $this->variants->contains(fn (ProductVariant $v) => $v->available() > 0);
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true) ?? $this->images->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
