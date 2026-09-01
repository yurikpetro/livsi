<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public static function current(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }
}
