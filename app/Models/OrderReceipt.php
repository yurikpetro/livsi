<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Кассовая или денежная операция по заказу после оплаты.
 *
 * closing — второй чек «полный расчёт» с КИЗ; refund — возврат денег.
 * Статусы — как у ЮKassa: pending, succeeded, canceled; new — ещё не отправлено.
 */
class OrderReceipt extends Model
{
    public const KIND_CLOSING = 'closing';

    public const KIND_REFUND = 'refund';

    public const NEW = 'new';

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const CANCELED = 'canceled';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::NEW => 'готовится',
            self::PENDING => 'ЮKassa обрабатывает',
            self::SUCCEEDED => 'готово',
            self::CANCELED => 'не прошло',
            default => (string) $this->status,
        };
    }
}
