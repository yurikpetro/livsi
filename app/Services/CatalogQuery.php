<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\Purpose;
use App\Support\Text;
use App\Models\Task;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Сборка запроса каталога по трём независимым осям (docs/06-scope-v2.md §3):
 * линейка — одна на товар, назначение и задача — много-ко-многим.
 *
 * Внутри одной оси значения объединяются по ИЛИ (товар подходит, если попал
 * хотя бы в одно), между осями — по И. Это привычное поведение фасетов.
 *
 * Счётчики у значений считаются с учётом всех фильтров, кроме своей оси:
 * иначе выбор значения обнулял бы все соседние и фильтр становился тупиком.
 */
class CatalogQuery
{
    public const PER_PAGE = 24;

    public const SORTS = [
        'popular'    => 'По популярности',
        'price_asc'  => 'Сначала дешевле',
        'price_desc' => 'Сначала дороже',
        'rating'     => 'По рейтингу',
    ];

    public const TABS = [
        'new'     => 'Новинки',
        'best'    => 'Бестселлеры',
        'bundles' => 'Наборы',
        'pro'     => 'PRO',
    ];

    public string $q = '';

    public ?string $line = null;

    /** @var array<string> */
    public array $purposes = [];

    /** @var array<string> */
    public array $tasks = [];

    public string $tab = '';

    public string $sort = 'popular';

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        $self->q        = trim(Text::utf8((string) $request->query('q', '')));
        $self->line     = $request->query('line') ?: null;
        $self->purposes = array_values(array_filter((array) $request->query('purpose', [])));
        $self->tasks    = array_values(array_filter((array) $request->query('task', [])));
        $self->tab      = (string) ($request->query('tab') ?: '');
        $self->sort     = array_key_exists((string) $request->query('sort'), self::SORTS)
            ? (string) $request->query('sort')
            : 'popular';

        return $self;
    }

    public function hasFilters(): bool
    {
        return $this->q !== '' || $this->line || $this->purposes || $this->tasks || $this->tab !== '';
    }

    /** Итоговый запрос со всеми фильтрами и сортировкой. */
    public function builder(): Builder
    {
        $query = Product::active()->with(['line', 'images', 'variants.quota']);

        $this->applyFilters($query);
        $this->applySort($query);

        return $query;
    }

    /** Счётчики значений по каждой оси. */
    public function facets(): array
    {
        return [
            'line' => ProductLine::active()->orderBy('sort')
                ->withCount(['products' => fn ($q) => $this->applyFilters($q, except: 'line')])
                ->get(),

            'purpose' => Purpose::where('is_active', true)->orderBy('sort')
                ->withCount(['products' => fn ($q) => $this->applyFilters($q, except: 'purpose')])
                ->get(),

            'task' => Task::where('is_active', true)->orderBy('sort')
                ->withCount(['products' => fn ($q) => $this->applyFilters($q, except: 'task')])
                ->get(),
        ];
    }

    /** Чипсы активных фильтров: подпись и адрес, снимающий именно этот фильтр. */
    public function chips(array $facets): Collection
    {
        $chips = collect();

        if ($this->q !== '') {
            $chips->push(['label' => '«' . $this->q . '»', 'url' => $this->urlWithout('q')]);
        }

        if ($this->tab !== '' && isset(self::TABS[$this->tab])) {
            $chips->push(['label' => self::TABS[$this->tab], 'url' => $this->urlWithout('tab')]);
        }

        if ($this->line && $line = $facets['line']->firstWhere('code', $this->line)) {
            $chips->push(['label' => $line->title, 'url' => $this->urlWithout('line')]);
        }

        foreach ($this->purposes as $code) {
            if ($purpose = $facets['purpose']->firstWhere('code', $code)) {
                $chips->push(['label' => $purpose->title, 'url' => $this->urlToggle('purpose', $code)]);
            }
        }

        foreach ($this->tasks as $code) {
            if ($task = $facets['task']->firstWhere('code', $code)) {
                $chips->push(['label' => $task->title, 'url' => $this->urlToggle('task', $code)]);
            }
        }

        return $chips;
    }

    // ─────────────────────────────────────── адреса

    /** Адрес с добавленным или снятым значением оси. */
    public function urlToggle(string $axis, string $value): string
    {
        $params = $this->toArray();

        if ($axis === 'line' || $axis === 'tab') {
            $params[$axis] = ($params[$axis] ?? null) === $value ? null : $value;
        } else {
            $key      = $axis === 'purpose' ? 'purpose' : 'task';
            $current  = $params[$key] ?? [];
            $params[$key] = in_array($value, $current, true)
                ? array_values(array_diff($current, [$value]))
                : [...$current, $value];
        }

        return $this->url($params);
    }

    public function urlWithout(string $axis): string
    {
        $params = $this->toArray();
        unset($params[$axis]);

        return $this->url($params);
    }

    public function urlWithSort(string $sort): string
    {
        return $this->url([...$this->toArray(), 'sort' => $sort]);
    }

    public function isActive(string $axis, string $value): bool
    {
        return match ($axis) {
            'line'    => $this->line === $value,
            'tab'     => $this->tab === $value,
            'purpose' => in_array($value, $this->purposes, true),
            'task'    => in_array($value, $this->tasks, true),
            default   => false,
        };
    }

    private function url(array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []);

        // Сортировка по умолчанию в адресе не нужна.
        if (($params['sort'] ?? null) === 'popular') {
            unset($params['sort']);
        }

        return route('catalog.index', $params);
    }

    private function toArray(): array
    {
        return [
            'q'       => $this->q ?: null,
            'line'    => $this->line,
            'purpose' => $this->purposes,
            'task'    => $this->tasks,
            'tab'     => $this->tab ?: null,
            'sort'    => $this->sort,
        ];
    }

    // ─────────────────────────────────────── фильтры

    /** @param  string|null  $except  ось, которую не применяем — для счётчиков */
    private function applyFilters(Builder $query, ?string $except = null): Builder
    {
        if ($except !== null) {
            $query->where('is_active', true);
        }

        if ($this->q !== '') {
            $query->where(fn ($q) => $this->applySearch($q));
        }

        if ($this->line && $except !== 'line') {
            $query->whereHas('line', fn ($q) => $q->where('code', $this->line));
        }

        if ($this->purposes && $except !== 'purpose') {
            $query->whereHas('purposes', fn ($q) => $q->whereIn('code', $this->purposes));
        }

        if ($this->tasks && $except !== 'task') {
            $query->whereHas('tasks', fn ($q) => $q->whereIn('code', $this->tasks));
        }

        match ($this->tab) {
            'pro'     => $query->where('is_pro', true),
            'bundles' => $query->where('is_bundle', true),
            'new'     => $query->where('badge', 'new'),
            'best'    => $query->where('badge', 'best'),
            default   => null,
        };

        return $query;
    }

    /**
     * Каждое слово запроса должно найтись хотя бы в одном месте: в тексте
     * товара, в линейке, назначении, задаче или в артикуле варианта.
     * Артикул важен для мастеров — они ищут по нему.
     */
    private function applySearch(Builder $query): void
    {
        foreach (Product::searchTerms($this->q) as $term) {
            $like = '%' . $term . '%';

            $query->where(function ($q) use ($term, $like) {
                $q->where('search_text', 'like', $like)
                    ->orWhereHas('line', fn ($l) => $l->whereRaw('lower(code) like ?', [$like]))
                    ->orWhereHas('purposes', fn ($p) => $p->where('code', 'like', $like))
                    ->orWhereHas('tasks', fn ($t) => $t->where('code', 'like', $like))
                    ->orWhereHas('variants', fn ($v) => $v->whereRaw('lower(sku) like ?', [$like]));
            });
        }
    }

    private function applySort(Builder $query): void
    {
        $cheapest = ProductVariant::select('price')
            ->whereColumn('product_variants.product_id', 'products.id')
            ->where('is_active', true)
            ->orderBy('price')
            ->limit(1);

        match ($this->sort) {
            'price_asc'  => $query->orderBy($cheapest),
            'price_desc' => $query->orderByDesc($cheapest),
            'rating'     => $query->orderByDesc('rating'),
            default      => $query->orderBy('sort'),
        };
    }
}