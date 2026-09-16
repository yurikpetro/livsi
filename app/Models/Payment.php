<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'paid_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Статусы ЮKassa: их же храним у себя, чтобы не переводить туда-обратно. */
    public const STATUS_PENDING             = 'pending';
    public const STATUS_WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const STATUS_SUCCEEDED           = 'succeeded';
    public const STATUS_CANCELED            = 'canceled';

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING             => 'Ожидает оплаты',
            self::STATUS_WAITING_FOR_CAPTURE => 'Ждёт подтверждения',
            self::STATUS_SUCCEEDED           => 'Оплачен',
            self::STATUS_CANCELED            => 'Отменён',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_CANCELED], true);
    }
}
