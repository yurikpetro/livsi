<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'utm'        => 'array',
        'consent_at' => 'datetime',
    ];

    public const TYPE_WHOLESALE = 'wholesale';
    public const TYPE_CONTRACT  = 'contract';
}
