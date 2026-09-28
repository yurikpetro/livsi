<?php

namespace App\Models;

use App\Monitoring\BusinessNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderIssue extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(fn (OrderIssue $issue) => app(BusinessNotifications::class)
            ->queue('order_issue', $issue->id, 'issues'));
    }

    public const REFUND_REQUEST = 'refund_request';

    public const DELIVERY_PROBLEM = 'delivery_problem';

    public static function kinds(): array
    {
        return [
            self::REFUND_REQUEST => 'Запрос на возврат',
            self::DELIVERY_PROBLEM => 'Проблема доставки',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
