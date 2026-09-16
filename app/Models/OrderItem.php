<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_gift'  => 'boolean',
        'vat_rate' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** «Мультипенка, 540 мл» — для писем и чека. */
    public function label(): string
    {
        return trim($this->title . ($this->option_label ? ', ' . $this->option_label : ''));
    }
}
