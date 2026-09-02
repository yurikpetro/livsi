<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductLine;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    // ─────────────────────────────────────────── микроразметка

    public function test_product_page_has_product_markup(): void
    {
        $html = $this->get('/product/skrab-dlya-tela')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"priceCurrency":"RUB"', $html);
        $this->assertStringContainsString('"price":"1290.00"', $html);
        $this->assertStringContainsString('schema.org/InStock', $html);
    }

    /**
     * Рейтинг размечать нельзя: отзывы собраны на Ozon, а не на сайте.
     * Разметка рейтинга, не подтверждённого содержимым страницы, — прямой
     * повод для санкций поисковика. См. docs/07-design-review.md §8.1.
     */
    public function test_product_markup_has_no_aggregate_rating(): void
    {
        $html = $this->get('/product/skrab-dlya-tela')->assertOk()->getContent();

        $this->assertStringNotContainsString('AggregateRating', $html);
        $this->assertStringNotContainsString('ratingValue', $html);
    }

    public function test_out_of_stock_product_is_marked_as_such(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        foreach ($product->variants as $variant) {
            $variant->quota->update(['allocated' => 0]);
        }

        $this->get('/product/' . $product->slug)
            ->assertOk()
            ->assertSee('schema.org/OutOfStock', escape: false);
    }

    // ─────────────────────────────────────────── canonical и robots

    public function test_sort_is_stripped_from_the_canonical_url(): void
    {
        // Сортировка меняет только порядок, поэтому канонический адрес без неё,
        // иначе четыре варианта сортировки дали бы четыре дубля страницы.
        $this->get('/catalog?line=fresh&sort=price_desc')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="' . route('catalog.index', ['line' => 'fresh']) . '">', escape: false);
    }

    public function test_pagination_keeps_its_own_canonical(): void
    {
        $html = $this->get('/catalog?page=2')->assertOk()->getContent();

        // Вторая страница не должна склеиваться с первой.
        $this->assertStringNotContainsString('canonical" href="' . route('catalog.index') . '"', $html);
    }

    public function test_search_results_are_not_indexed(): void
    {
        $this->get('/catalog?q=' . urlencode('скраб'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', escape: false);
    }

    public function test_plain_catalog_is_indexed(): void
    {
        $this->get('/catalog')->assertOk()->assertDontSee('name="robots"', escape: false);
    }

    // ─────────────────────────────────────────── карта сайта

    public function test_sitemap_is_valid_xml_and_lists_products(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $response->assertHeader('Content-Type', 'application/xml');

        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml, 'Карта сайта должна быть валидным XML');

        // Второй аргумент false обязателен: у всех детей одно имя `url`,
        // и с сохранением ключей SimpleXML вернул бы только один элемент.
        $locs = array_map(fn ($url) => (string) $url->loc, iterator_to_array($xml->url, false));

        $this->assertGreaterThan(10, count($locs));

        $this->assertContains(route('home'), $locs);
        $this->assertContains(route('catalog.index'), $locs);
        $this->assertContains(route('catalog.pro'), $locs);
        $this->assertContains(route('catalog.show', Product::where('slug', 'skrab-dlya-tela')->firstOrFail()), $locs);
        $this->assertContains(route('catalog.line', ProductLine::where('code', 'fresh')->firstOrFail()), $locs);
    }

    public function test_sitemap_excludes_inactive_products(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->update(['is_active' => false]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/product/skrab-dlya-tela');
    }

    // ─────────────────────────────────────────── лендинги линеек

    public function test_line_landing_page_shows_its_products(): void
    {
        $this->get('/line/fresh')
            ->assertOk()
            ->assertSee('FRESH')
            ->assertSee('Мультипенка для рук и стоп')
            ->assertDontSee('Скраб для тела');
    }

    public function test_line_landing_page_shows_the_line_description(): void
    {
        $line = ProductLine::where('code', 'sweet')->firstOrFail();

        $this->get('/line/sweet')->assertOk()->assertSee($line->description);
    }

    public function test_pro_landing_page_shows_only_professional_products(): void
    {
        $this->get('/pro')
            ->assertOk()
            ->assertSee('Кератолитический гель для стоп')
            ->assertDontSee('Скраб для тела');
    }

    public function test_unknown_line_returns_404(): void
    {
        $this->get('/line/nope')->assertNotFound();
    }

    public function test_inactive_line_returns_404(): void
    {
        ProductLine::where('code', 'warm')->update(['is_active' => false]);

        $this->get('/line/warm')->assertNotFound();
    }

    // ─────────────────────────────────────────── галерея и аккордеоны

    public function test_product_page_renders_a_gallery_with_thumbnails(): void
    {
        $product = Product::with('images')->where('slug', 'skrab-dlya-tela')->firstOrFail();

        $this->assertGreaterThan(1, $product->images->count());

        $html = $this->get('/product/' . $product->slug)->assertOk()->getContent();

        foreach ($product->images as $image) {
            $this->assertStringContainsString($image->path, $html);
        }

        $this->assertStringContainsString('aria-label="Кадр 1"', $html);
    }

    public function test_single_image_product_has_no_thumbnail_strip(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->images()->where('is_primary', false)->delete();

        $this->get('/product/' . $product->slug)
            ->assertOk()
            ->assertDontSee('aria-label="Кадр 1"', escape: false);
    }

    public function test_accordions_render_only_filled_sections(): void
    {
        // Способ применения заполнен, состав (INCI) — нет: его ждём
        // из документации производителя, придумывать нельзя.
        $this->get('/product/skrab-dlya-tela')
            ->assertOk()
            ->assertSee('Способ применения')
            ->assertDontSee('>Состав<', escape: false);
    }

    public function test_pro_product_shows_active_ingredients(): void
    {
        $this->get('/product/keratoliticheskiy-gel-dlya-stop')
            ->assertOk()
            ->assertSee('Действующие вещества');
    }
}
