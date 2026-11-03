<?php

namespace App\Models;

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
            self::STATUS_REFUNDED => 'Возвращён',
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
