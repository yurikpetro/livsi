<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка нашего пользователя к аккаунту у внешнего провайдера.
 *
 * Отдельной таблицей, а не колонкой в `users`: у покупателя будет
 * несколько способов входа — код из СМС, Яндекс, VK — и все они ведут
 * к одному человеку.
 */
class SocialAccount extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
