<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Адрес, на котором посетитель или робот получил 404. Пишет App\Seo\MissingPages. */
class MissingPage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ignored' => 'boolean',
    ];

    public function redirect(): ?SeoRedirect
    {
        return SeoRedirect::query()->where('from_path', $this->path)->first();
    }
}
