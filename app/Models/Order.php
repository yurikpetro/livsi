<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'utm'                  => 'array',
        'consent_at'           => 'datetime',
        'marketing_consent_at' => 'datetime',
        'paid_at'              => 'datetime',
        'cancelled_at'         => 'datetime',
    ];

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID             = 'paid';
    public const STATUS_CANCELLED        = 'cancelled';
    public const STATUS_REFUNDED         = 'refunded';

    public static function statuses(): array
    {
        return [
            self::STATUS_AWAITING_PAYMENT => 'Ждёт оплаты',
            self::STATUS_PAID             => 'Оплачен',
            self::STATUS_CANCELLED        => 'Отменён',
            self::STATUS_REFUNDED         => 'Возвращён',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function giftVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'gift_variant_id');
    }

    /** Последняя попытка оплаты — её статус и показываем покупателю. */
    public function lastPayment(): ?Payment
    {
        return $this->payments()->latest('id')->first();
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Ссылка на заказ для гостя.
     *
     * Кабинета у гостя нет, а заказ ему показывать надо — поэтому доступ
     * по неугадываемому токену из письма (docs/06-scope-v2.md § 2.7).
     */
    public function accessUrl(): string
    {
        return route('order.show', ['order' => $this->number, 'token' => $this->access_token]);
    }
}
