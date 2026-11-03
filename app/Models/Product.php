<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'auto_recommendations' => true,
    ];

    protected $casts = [
        'auto_recommendations' => 'boolean',
        'recommended_product_ids' => 'array',
        'is_pro' => 'boolean',
        'is_bundle' => 'boolean',
        'is_active' => 'boolean',
        'rating' => 'float',
        'vat_rate' => 'float',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if (! $product->exists || $product->isDirty('product_line_id')) {
                $product->is_pro = $product->line()->whereIn('code', ProductLine::PROFESSIONAL_CODES)->exists();
            }
        });

        // Поисковый текст пересобирается после сохранения, а не до: связи
        // (назначения, задачи) синхронизируются уже после самой модели.
        static::saved(fn (Product $product) => $product->rebuildSearchText());
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class, 'product_line_id');
    }

    public function isProfessional(): bool
    {
        return $this->line?->isProfessional() || $this->is_pro;
    }

    /** Старый флаг сохраняет в общей подборке PRO товары, ожидающие выбора линейки. */
    public function scopeProfessional(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->whereHas('line', fn (Builder $line): Builder => $line->whereIn('code', ProductLine::PROFESSIONAL_CODES))
            ->orWhere('is_pro', true));
    }

    public function purposes(): BelongsToMany
    {
        return $this->belongsToMany(Purpose::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function declarations(): BelongsToMany
    {
        return $this->belongsToMany(Declaration::class)->where('is_active', true)->orderBy('sort');
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class, 'bundle_product_id');
    }

    public function defaultVariant(): ?ProductVariant
    {
        return $this->variants->firstWhere('is_default', true) ?? $this->variants->first();
    }

    /** Витрина предпочитает доступный активный вариант, затем активный без остатка. */
    public function storefrontVariant(): ?ProductVariant
    {
        $active = $this->variants->where('is_active', true);
        $available = $active->filter(fn (ProductVariant $variant): bool => $variant->available() > 0);

        return $available->firstWhere('is_default', true)
            ?? $available->first()
            ?? $active->firstWhere('is_default', true)
            ?? $active->first();
    }

    /** @return Collection<int, Product> */
    public function recommendedProducts(int $limit = 3): Collection
    {
        $query = self::active()
            ->whereKeyNot($this->getKey())
            ->with(['line', 'images', 'variants.quota']);

        if ($this->auto_recommendations) {
            return $query
                ->orderByRaw('CASE WHEN product_line_id = ? THEN 0 ELSE 1 END', [$this->product_line_id])
                ->orderBy('sort')
                ->orderBy('id')
                ->limit($limit)
                ->get();
        }

        $ids = $this->recommended_product_ids ?? [];
        $products = $query->whereKey($ids)->get()->keyBy('id');

        return new Collection(collect($ids)
            ->unique()
            ->map(fn (int|string $id): ?Product => $products->get($id))
            ->filter()
            ->take($limit)
            ->values()
            ->all());
    }

    /** Минимальная цена среди активных вариантов, в копейках. */
    public function priceFrom(): ?int
    {
        return $this->variants->where('is_active', true)->min('price');
    }

    /** Есть ли хоть один вариант, доступный к продаже из квоты сайта. */
    public function isAvailable(): bool
    {
        return $this->variants->contains(fn (ProductVariant $variant): bool => $variant->is_active && $variant->available() > 0);
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true) ?? $this->images->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ─────────────────────────────────────────── поиск

    /**
     * Разбор строки запроса на слова в нижнем регистре.
     *
     * Нормализуем в PHP через mb_strtolower: SQLite приводит регистр только
     * для ASCII, и «ПЕНКА» не нашлась бы по «пенка».
     *
     * @return array<string>
     */
    public static function searchTerms(string $query): array
    {
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(
            array_values(array_filter($terms, fn (string $t) => mb_strlen($t) >= 2)),
            0,
            5,
        );
    }

    /**
     * Пересобирает `search_text`: собственные атрибуты плюс названия линейки,
     * назначений и задач. Артикулы вариантов в поле не кладём — они ищутся
     * через whereHas, а вариантов у товара может быть много.
     *
     * Связи читаются из базы заново, а не из загруженного состояния: метод
     * вызывается сразу после сохранения, когда старые связи в памяти устарели.
     */
    public function rebuildSearchText(): void
    {
        $parts = [
            $this->title,
            $this->short_description,
            $this->aroma,
            $this->effect,
            $this->composition,
            $this->active_ingredients,
            $this->line()->value('title'),
            $this->purposes()->pluck('title')->implode(' '),
            $this->tasks()->pluck('title')->implode(' '),
        ];

        $text = mb_strtolower(Str::squish(implode(' ', array_filter($parts))));

        if ($text === (string) $this->search_text) {
            return;
        }

        // saveQuietly, иначе хук saved вызвал бы сам себя.
        $this->search_text = $text;
        $this->saveQuietly();
    }
}
