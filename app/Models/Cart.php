<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Cart extends Model
{
    protected $guarded = [];

    protected $casts = [
        'utm'              => 'array',
        'last_activity_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Оплачиваемые позиции — без подарка. */
    public function paidItems(): Collection
    {
        return $this->items->where('is_gift', false);
    }

    public function giftItem(): ?CartItem
    {
        return $this->items->firstWhere('is_gift', true);
    }

    /** Сумма оплачиваемых позиций в копейках. */
    public function subtotal(): int
    {
        return $this->paidItems()->sum(fn (CartItem $item) => $item->lineTotal());
    }

    public function totalQty(): int
    {
        return (int) $this->paidItems()->sum('qty');
    }

    public function isEmpty(): bool
    {
        return $this->paidItems()->isEmpty();
    }
}