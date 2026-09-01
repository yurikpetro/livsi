<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_featured'  => 'boolean',
        'is_active'    => 'boolean',
        'published_on' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_active', true)->where('is_featured', true);
    }

    /** Подпись источника для витрины: отзыв не с сайта нельзя выдавать за свой. */
    public function sourceLabel(): string
    {
        return $this->source_label ?: match ($this->source) {
            'ozon'        => 'Ozon',
            'wildberries' => 'Wildberries',
            default       => 'LIVSI',
        };
    }
}
