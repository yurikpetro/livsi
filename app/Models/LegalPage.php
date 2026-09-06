<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalPage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'is_active'   => 'boolean',
    ];

    /**
     * Документы, у которых на витрине есть собственный адрес.
     *
     * Список фиксированный: маршруты объявляются статически, иначе
     * `route:cache` перестанет работать, а адреса документов
     * не должны зависеть от содержимого базы.
     */
    public const SLUGS = [
        'offer'    => 'Публичная оферта',
        'privacy'  => 'Политика конфиденциальности',
        'consent'  => 'Согласие на обработку персональных данных',
        'delivery' => 'Доставка и оплата',
        'returns'  => 'Возврат товара',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Короткая подпись для футера; если не задана — полное название. */
    public function menuTitle(): string
    {
        return $this->menu_title ?: $this->title;
    }

    /**
     * Текст не проверен юристом — это черновик разработчика.
     *
     * Публично об этом не сообщаем (заявление «наша оферта не проверена»
     * само по себе вредно), но в админке помечаем, пока заказчик
     * не подтвердит проверку.
     */
    public function isDraft(): bool
    {
        return $this->reviewed_at === null;
    }
}
