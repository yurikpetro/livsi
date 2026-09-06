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

    public const STATUS_NEW         = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_REJECTED    = 'rejected';

    /**
     * Категории продукта для контрактного производства — как в прототипе.
     *
     * Один источник на форму, валидацию и админку: список, размноженный
     * по трём местам, разъезжается на первой же правке.
     */
    public const CONTRACT_CATEGORIES = [
        'face'  => 'Уход за лицом',
        'body'  => 'Уход за телом',
        'hands' => 'Уход за руками и ногами',
        'pro'   => 'Профессиональная косметика',
        'other' => 'Другое',
    ];

    /**
     * Формат продаж для оптовой заявки — как в прототипе.
     *
     * Один источник на форму, валидацию и админку: список, размноженный
     * по трём местам, разъезжается на первой же правке.
     */
    public const SALES_FORMATS = [
        'shop'         => 'Магазин',
        'salon'        => 'Салон или студия',
        'online'       => 'Интернет-магазин',
        'marketplaces' => 'Маркетплейсы',
        'other'        => 'Другое',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_WHOLESALE => 'Оптовое партнёрство',
            self::TYPE_CONTRACT  => 'Контрактное производство',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_NEW         => 'Новая',
            self::STATUS_IN_PROGRESS => 'В работе',
            self::STATUS_DONE        => 'Обработана',
            self::STATUS_REJECTED    => 'Отклонена',
        ];
    }

    public function typeLabel(): string
    {
        return self::types()[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    /** Человеческое название категории: в базе лежит код. */
    public function categoryLabel(): ?string
    {
        return $this->product_category
            ? (self::CONTRACT_CATEGORIES[$this->product_category] ?? $this->product_category)
            : null;
    }

    /** Человеческое название формата продаж: в базе лежит код. */
    public function salesFormatLabel(): ?string
    {
        return $this->sales_format
            ? (self::SALES_FORMATS[$this->sales_format] ?? $this->sales_format)
            : null;
    }
}
