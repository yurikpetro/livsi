<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockQuotaMovement extends Model
{
    protected $guarded = [];

    public function quota(): BelongsTo
    {
        return $this->belongsTo(StockQuota::class, 'stock_quota_id');
    }
}
