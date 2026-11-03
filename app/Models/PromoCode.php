<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Промокод: скидка в процентах, в рублях или бесплатная доставка.
 * Личный — с клиентом (customer_id), общий — без. Использование — заказы
 * с этим кодом: ожидающие оплаты и оплаченные. Отменённый или возвращённый
 * заказ код не тратит.
 */
class PromoCode extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const FREE_DELIVERY = 'free_delivery';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'once_per_customer' => 'boolean',
    ];

    public static function kinds(): array
    {
        return [
            self::PERCENT => 'Скидка, %',
            self::FIXED => 'Скидка, ₽',
            self::FREE_DELIVERY => 'Бесплатная доставка',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function mailing(): BelongsTo
    {
        return $this->belongsTo(Mailing::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Заказы, которые держат код: ждут оплаты или оплачены. */
    public function liveOrders(): HasMany
    {
        return $this->orders()->whereIn('status', [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PAID]);
    }

    /** Что даёт код — для людей: «Скидка 10 %», «Скидка 300 ₽ от 1 500 ₽», «Бесплатная доставка». */
    public function benefit(): string
    {
        $what = match ($this->kind) {
            self::PERCENT => 'Скидка '.$this->value.' %',
            self::FIXED => 'Скидка '.Money::rub((int) $this->value),
            self::FREE_DELIVERY => 'Бесплатная доставка',
            default => $this->kind,
        };

        return $what.($this->min_total ? ' на заказ от '.Money::rub((int) $this->min_total) : '');
    }

    /** Состояние для списков: действует / использован / истёк / выключен. */
    public function stateLabel(): string
    {
        $used = $this->relationLoaded('liveOrders') ? $this->liveOrders->count() : $this->liveOrders()->count();

        return match (true) {
            ! $this->active => 'выключен',
            $this->max_uses !== null && $used >= $this->max_uses => 'использован',
            $this->expires_at !== null && $this->expires_at->isPast() => 'истёк',
            default => 'действует',
        };
    }
}
