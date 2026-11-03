<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Клиент сайта: зарегистрированный или купивший гостем. Ключ — почта.
 * Здесь же согласие на рассылку (из последнего заказа с галочкой)
 * и отписка по ссылке из письма.
 */
class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'marketing_consent_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    /** Можно ли слать рекламу: было согласие и после него не отписался. */
    public function canReceiveMarketing(): bool
    {
        return $this->marketing_consent_at !== null && filled($this->email)
            && ($this->unsubscribed_at === null || $this->unsubscribed_at->lt($this->marketing_consent_at));
    }

    public function scopeSubscribed(Builder $query): void
    {
        $query->whereNotNull('marketing_consent_at')->whereNotNull('email')
            ->where(fn (Builder $q) => $q->whereNull('unsubscribed_at')->orWhereColumn('unsubscribed_at', '<', 'marketing_consent_at'));
    }

    /** Заказы, за которые заплатили (в том числе потом вернувшиеся). */
    public function scopeWithStats(Builder $query): void
    {
        $paid = [Order::STATUS_PAID, Order::STATUS_REFUNDED];
        $query->withCount(['orders as paid_orders_count' => fn (Builder $q) => $q->whereIn('status', $paid)])
            ->withSum(['orders as paid_total' => fn (Builder $q) => $q->where('status', Order::STATUS_PAID)], 'total')
            ->withMax(['orders as last_order_at' => fn (Builder $q) => $q->whereIn('status', $paid)], 'paid_at');
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && str_contains($email, '@') ? mb_substr($email, 0, 255) : null;
    }

    /** Телефон цифрами, российский — с 7 впереди: «8 900…» и «+7 900…» — один номер. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        return strlen($digits) >= 10 ? substr($digits, 0, 20) : null;
    }
}
