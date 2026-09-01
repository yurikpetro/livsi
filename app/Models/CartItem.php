<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_gift' => 'boolean',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Цена берётся из варианта на момент показа, а не из снимка в корзине:
     * снимок фиксируется только при оформлении заказа, в order_items.
     */
    public function unitPrice(): int
    {
        return (int) $this->variant->price;
    }

    public function lineTotal(): int
    {
        return $this->is_gift ? 0 : $this->unitPrice() * $this->qty;
    }
}