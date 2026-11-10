<?php

namespace App\Models;

use App\Support\Fulfillment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delivery_quote' => 'array',
        'shipment_sender' => 'array',
        'shipment_requested_at' => 'datetime',
        'shipment_created_at' => 'datetime',
        'shipment_automatic' => 'boolean',
        'shipment_checked_at' => 'datetime',
        'shipment_notified_at' => 'datetime',
        'shipment_data' => 'array',
        'shipment_synced_at' => 'datetime',
        'fulfillment_status_at' => 'datetime',
        'utm' => 'array',
        'consent_at' => 'datetime',
        'marketing_consent_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'returned_to_stock_at' => 'datetime',
        'promo_free_delivery' => 'boolean',
    ];

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            if ($order->shipment_automatic && in_array($order->shipment_state, ['created', 'unknown', 'failed', 'cancelled'], true)) {
                $order->shipment_event_key = $order->shipmentNotificationKey();
            }
        });
    }

    public function shipmentNotificationKey(): string
    {
        return hash('sha256', json_encode([$this->shipment_state, $this->apiship_order_id,
            $this->shipment_external_id, $this->shipment_status_code,
            $this->shipment_provider_status, $this->shipment_status_name,
            $this->shipment_tracking_number, $this->shipment_error], JSON_THROW_ON_ERROR));
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_AWAITING_PAYMENT => 'Ждёт оплаты',
            self::STATUS_PAID => 'Оплачен',
            self::STATUS_CANCELLED => 'Отменён',
            self::STATUS_REFUNDED => 'Деньги возвращены',
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

    public function issues(): HasMany
    {
        return $this->hasMany(OrderIssue::class);
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

    public function fulfillmentEvents(): HasMany
    {
        return $this->hasMany(OrderFulfillmentEvent::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(OrderItemMark::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(OrderReceipt::class);
    }

    public function receipt(string $kind): ?OrderReceipt
    {
        return $this->receipts()->where('kind', $kind)->first();
    }

    /** Заявки покупателя на возврат после получения. */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    /** Последняя заявка на возврат — её состояние и показываем. */
    public function latestReturn(): ?OrderReturn
    {
        return $this->returns()->latest('id')->first();
    }

    /** Заявка, которая ещё в работе: ждёт решения, товара или денег. */
    public function activeReturn(): ?OrderReturn
    {
        return $this->returns()->whereIn('status', [OrderReturn::REQUESTED, OrderReturn::APPROVED, OrderReturn::RECEIVED])
            ->latest('id')->first();
    }

    /** Заказы без оформленного возврата денег: их ещё собирают и везут. */
    public function scopeWithoutRefund(Builder $query): void
    {
        $query->whereDoesntHave('receipts', fn (Builder $q) => $q->where('kind', OrderReceipt::KIND_REFUND)
            ->whereIn('status', [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED]));
    }

    /**
     * Номер заказа для службы доставки. Первая заявка — номер как есть,
     * после отмены отправления — «LV-…-2», «-3»: службы помнят старый номер
     * и на повтор вернули бы уже отменённую заявку.
     */
    public function shipmentRef(): string
    {
        $attempt = (int) ($this->shipment_attempt ?? 1);

        return $attempt > 1 ? $this->number.'-'.$attempt : (string) $this->number;
    }

    /**
     * Итог заказа одной строкой — когда деньги и доставка вместе говорят
     * больше, чем по отдельности: «отменён до отправки», «не забрал»,
     * «возврат после получения». У обычного заказа в пути — null.
     */
    public function outcome(): ?string
    {
        $delivery = Fulfillment::current($this);
        $refunded = $this->status === self::STATUS_REFUNDED;
        $stock = $this->returned_to_stock_at ? ' · принят на склад' : '';

        return match (true) {
            $refunded && in_array($delivery, [null, Fulfillment::AWAITING, Fulfillment::ASSEMBLED], true) => 'Отменён до отправки',
            $delivery === Fulfillment::RETURNED && $refunded => 'Не забрал · деньги возвращены'.$stock,
            $delivery === Fulfillment::RETURNED => $this->returned_to_stock_at ? 'Не забрал · принят на склад' : 'Не забрал · едет обратно',
            $refunded && $delivery === Fulfillment::DELIVERED => 'Возврат после получения'.$stock,
            $refunded => 'Деньги возвращены, посылка в пути',
            default => null,
        };
    }

    /** Можно ли принять товар заказа обратно на склад. */
    public function canReturnToStock(): bool
    {
        $delivery = Fulfillment::current($this);

        return ! $this->returned_to_stock_at && (
            ($delivery === Fulfillment::RETURNED && in_array($this->status, [self::STATUS_PAID, self::STATUS_REFUNDED], true))
            || ($this->status === self::STATUS_REFUNDED && in_array($delivery, [Fulfillment::SHIPPED, Fulfillment::AT_PICKUP, Fulfillment::DELIVERED], true))
        );
    }

    /** Отправление создано — через ApiShip или напрямую у службы. */
    public function hasShipment(): bool
    {
        return filled($this->apiship_order_id) || filled($this->shipment_external_id);
    }

    /** Номер отправления для людей: у службы или в ApiShip. */
    public function shipmentReference(): ?string
    {
        return match (true) {
            filled($this->shipment_external_id) => match ($this->shipment_provider) {
                'cdek' => 'СДЭК', 'yataxi' => 'Яндекс', 'ozondel' => 'Ozon', default => 'Служба',
            }.' №'.($this->shipment_data['number'] ?? $this->shipment_external_id),
            filled($this->apiship_order_id) => 'ApiShip №'.$this->apiship_order_id,
            default => null,
        };
    }

    public function shipmentLabel(): string
    {
        return match ($this->shipment_state) {
            'queued' => 'Ожидает автоматического создания',
            'created' => $this->shipment_status_name ?: ($this->shipment_external_id ? 'Создано у службы' : 'Принято ApiShip'),
            'cancelled' => 'Отменено',
            'creating' => 'Создание отправления',
            'unknown' => 'Требуется проверка',
            'failed' => 'Заявка отклонена',
            default => 'Не создано',
        };
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
