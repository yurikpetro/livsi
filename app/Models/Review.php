<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Review extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_featured'  => 'boolean',
        'is_active'    => 'boolean',
        'published_on' => 'date',
    ];

    /**
     * Места в блоке на главной. Композиция в макете фиксированная:
     * большая карточка слева на две строки и две узкие справа.
     */
    public const SLOTS = [
        'main'   => 'Большая карточка слева',
        'top'    => 'Правая верхняя',
        'bottom' => 'Правая нижняя',
    ];

    /** Акцент карточки: в макете отличается только цвет звёзд у PRO. */
    public const ACCENTS = [
        'fresh' => 'Обычный',
        'base'  => 'Обычный (тёплый)',
        'pro'   => 'PRO — синие звёзды',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Три карточки блока, разложенные по местам.
     *
     * Ключ — место в блоке, чтобы шаблон обращался к нему по имени
     * и не зависел от порядка записей в базе.
     *
     * @return Collection<string, Review>
     */
    public static function homepageBlock(): Collection
    {
        return static::active()
            ->whereIn('slot', array_keys(self::SLOTS))
            ->with('product.line')
            ->get()
            ->keyBy('slot');
    }

    public function slotLabel(): string
    {
        return self::SLOTS[$this->slot] ?? '—';
    }

    /** Буква в кружке рядом с именем. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim((string) $this->author), 0, 1));
    }

    /** Звёзды строкой: в макете их ровно столько, сколько стоит оценка. */
    public function stars(): string
    {
        return str_repeat('★', max(1, min(5, (int) $this->rating)));
    }

    /**
     * Строка над цитатой. Заполняется вручную, потому что в макете это
     * не всегда товар: у третьей карточки там «PRO / PEDICURE».
     * Если поле пустое, собираем из товара — чтобы место не пустовало.
     */
    public function captionLine(): ?string
    {
        if (filled($this->caption)) {
            return $this->caption;
        }

        if (! $this->product) {
            return null;
        }

        return trim(($this->product->line?->title ?? '') . ' / ' . $this->product->title, ' /');
    }

    /** Подпись источника — для внутреннего учёта и админки, на витрине её нет. */
    public function sourceLabel(): ?string
    {
        if (blank($this->source)) {
            return null;
        }

        return $this->source_label ?: match ($this->source) {
            'ozon'        => 'Ozon',
            'wildberries' => 'Wildberries',
            'yandex'      => 'Яндекс Маркет',
            default       => 'LIVSI',
        };
    }
}
