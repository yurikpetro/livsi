<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockQuota;
use App\Services\CatalogQuery;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CatalogFacetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** Внутри одной оси значения объединяются по ИЛИ. */
    public function test_multiple_values_within_one_axis_are_combined_with_or(): void
    {
        $hands = $this->found(['purpose' => ['hands']]);
        $body  = $this->found(['purpose' => ['body']]);
        $both  = $this->found(['purpose' => ['hands', 'body']]);

        $this->assertGreaterThan($hands, $both);
        $this->assertGreaterThan($body, $both);
        $this->assertSame($hands + $body, $both);
    }

    /** Между осями — по И. */
    public function test_different_axes_are_combined_with_and(): void
    {
        $fresh = $this->found(['line' => 'fresh']);
        $both  = $this->found(['line' => 'fresh', 'task' => ['cleansing']]);

        $this->assertLessThanOrEqual($fresh, $both);
        $this->assertGreaterThan(0, $both);
    }

    public function test_incompatible_combination_returns_nothing(): void
    {
        $this->assertSame(0, $this->found(['line' => 'warm', 'purpose' => ['manicure']]));
    }

    /**
     * Счётчик своей оси считается без её фильтра. Иначе после выбора FRESH
     * все остальные линейки показали бы ноль, и фильтр стал бы тупиком.
     */
    public function test_facet_counts_ignore_their_own_axis(): void
    {
        $facets = $this->catalogQuery(['line' => 'fresh'])->facets();

        $this->assertGreaterThan(0, $facets['line']->firstWhere('code', 'sweet')->products_count);
        $this->assertGreaterThan(0, $facets['line']->firstWhere('code', 'fresh')->products_count);
    }

    /** А вот счётчики чужих осей должны сужаться. */
    public function test_facet_counts_of_other_axes_are_narrowed(): void
    {
        $all   = $this->catalogQuery([])->facets();
        $fresh = $this->catalogQuery(['line' => 'fresh'])->facets();

        $this->assertLessThan(
            $all['purpose']->firstWhere('code', 'body')->products_count,
            $fresh['purpose']->firstWhere('code', 'body')->products_count + 1,
        );

        $this->assertSame(0, $fresh['purpose']->firstWhere('code', 'body')->products_count);
    }

    public function test_active_filters_are_shown_as_chips(): void
    {
        $this->get('/catalog?line=fresh&purpose[]=hands')
            ->assertOk()
            ->assertSee('Выбрано')
            ->assertSee('Сбросить всё');
    }

    /** Клик по чипсу снимает ровно один фильтр, остальные остаются. */
    public function test_chip_url_removes_only_its_own_filter(): void
    {
        $query  = $this->catalogQuery(['line' => 'fresh', 'purpose' => ['hands', 'feet']]);
        $facets = $query->facets();
        $chips  = $query->chips($facets);

        $handsChip = $chips->firstWhere('label', 'Руки');

        $this->assertNotNull($handsChip);
        $this->assertStringContainsString('line=fresh', urldecode($handsChip['url']));
        $this->assertStringContainsString('feet', urldecode($handsChip['url']));
        $this->assertStringNotContainsString('hands', urldecode($handsChip['url']));
    }

    public function test_toggling_a_value_twice_returns_to_the_original_url(): void
    {
        $query = $this->catalogQuery([]);

        $withFresh = $query->urlToggle('line', 'fresh');
        $this->assertStringContainsString('line=fresh', urldecode($withFresh));

        $withFreshActive = $this->catalogQuery(['line' => 'fresh']);
        $this->assertStringNotContainsString('line=fresh', urldecode($withFreshActive->urlToggle('line', 'fresh')));
    }

    /** Сортировка по умолчанию не должна мусорить в адресе. */
    public function test_default_sort_is_not_added_to_the_url(): void
    {
        $url = $this->catalogQuery([])->urlWithSort('popular');

        $this->assertStringNotContainsString('sort=', $url);
    }

    public function test_price_sorting_orders_products(): void
    {
        $asc  = $this->firstProductSlug(['sort' => 'price_asc']);
        $desc = $this->firstProductSlug(['sort' => 'price_desc']);

        $this->assertNotSame($asc, $desc);
        $this->assertSame('multipenka-dlya-ruk-i-stop', $asc);  // 450 ₽ — самый дешёвый
        $this->assertSame('skrab-dlya-tela', $desc);            // 1 290 ₽ — самый дорогой
    }

    public function test_catalog_is_paginated(): void
    {
        $this->makeExtraProducts(20);

        $response = $this->get('/catalog')->assertOk();

        // 28 товаров при 24 на страницу — вторая страница обязана появиться.
        $response->assertSee('page=2');
        $this->assertSame(CatalogQuery::PER_PAGE, $this->countIn($response->getContent()));

        $this->get('/catalog?page=2')->assertOk();
    }

    /** Фильтры не должны теряться при переходе на вторую страницу. */
    public function test_filters_survive_pagination(): void
    {
        $this->makeExtraProducts(30, purposeCode: 'hands');

        $html = $this->get('/catalog?purpose[]=hands')->assertOk()->getContent();

        $this->assertStringContainsString('purpose', $html);
        $this->assertStringContainsString('page=2', $html);
    }

    // ───────────────────────────────────────────── helpers

    private function catalogQuery(array $params): CatalogQuery
    {
        return CatalogQuery::fromRequest(Request::create('/catalog', 'GET', $params));
    }

    private function found(array $params): int
    {
        return $this->catalogQuery($params)->builder()->count();
    }

    private function firstProductSlug(array $params): string
    {
        return $this->catalogQuery($params)->builder()->first()->slug;
    }

    private function countIn(string $html): int
    {
        preg_match_all('~/product/([a-z0-9-]+)~', $html, $matches);

        return count(array_unique($matches[1]));
    }

    private function makeExtraProducts(int $count, ?string $purposeCode = null): void
    {
        $purpose = $purposeCode ? \App\Models\Purpose::where('code', $purposeCode)->first() : null;

        for ($i = 1; $i <= $count; $i++) {
            $product = Product::create([
                'slug'      => 'test-product-' . $i,
                'title'     => 'Тестовый товар ' . $i,
                'is_active' => true,
                'sort'      => 1000 + $i,
            ]);

            if ($purpose) {
                $product->purposes()->attach($purpose);
            }

            $variant = ProductVariant::create([
                'product_id'    => $product->id,
                'sku'           => 'TEST' . $i,
                'option_volume' => '100 мл',
                'price'         => 10000 + $i,
                'is_default'    => true,
                'is_active'     => true,
            ]);

            StockQuota::create(['product_variant_id' => $variant->id, 'allocated' => 5]);
        }
    }
}
