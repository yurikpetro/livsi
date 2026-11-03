<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Рассылка клиентам: письмо (позже — SMS), к нему по желанию личный промокод каждому. */
class Mailing extends Model
{
    public const SENDING = 'sending';

    public const DONE = 'done';

    protected $guarded = [];

    protected $casts = ['finished_at' => 'datetime'];

    public function recipients(): HasMany
    {
        return $this->hasMany(MailingRecipient::class);
    }

    public function statusLabel(): string
    {
        return $this->status === self::DONE ? 'отправлена' : 'отправляется';
    }
}
