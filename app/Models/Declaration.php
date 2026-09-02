<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Declaration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_on'   => 'date',
        'valid_until' => 'date',
        'is_active'   => 'boolean',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function hasFile(): bool
    {
        return (bool) $this->file_path;
    }

    /** Истёкшую декларацию нельзя выдавать за действующую. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function fileSizeForHumans(): ?string
    {
        if (! $this->file_size) {
            return null;
        }

        return $this->file_size >= 1048576
            ? round($this->file_size / 1048576, 1) . ' МБ'
            : round($this->file_size / 1024) . ' КБ';
    }
}