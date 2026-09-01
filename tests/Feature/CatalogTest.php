<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Полка, с которой')
            ->assertSee('FRESH');
    }

    public function test_catalog_lists_all_active_products(): void
    {
        $this->get('/catalog')
            ->assertOk()
            ->assertSee('Мультипенка для рук и стоп');

        $this->assertSame(8, Product::active()->count());
    }

    /** Ароматическая линейка — одна на товар. */
    public function test_line_filter_narrows_results(): void
    {
        $response = $this->get('/catalog?line=fresh');

        $response->assertOk();
        $this->assertSame(3, $this->productCount($response->getContent()));
    }

    /** Назначение — независимая ось, many-to-many. */
    public function test_purpose_filter_narrows_results(): void
    {
        $response = $this->get('/catalog?purpose=manicure');

        $response->assertOk();
        $this->assertSame(2, $this->productCount($response->getContent()));
    }

    /** Задача — вторая независимая ось. */
    public function test_task_filter_narrows_results(): void
    {
        $response = $this->get('/catalog?task=soften');

        $response->assertOk();
        $this->assertSame(2, $this->productCount($response->getContent()));
    }

    /** PRO — это флаг на товаре, а не пятая линейка. */
    public function test_pro_tab_returns_only_professional_products(): void
    {
        $response = $this->get('/catalog?tab=pro');

        $response->assertOk();
        $this->assertSame(1, $this->productCount($response->getContent()));
        $response->assertSee('Кератолитический гель для стоп');
    }

    public function test_incompatible_filter_combination_shows_empty_state(): void
    {
        $this->get('/catalog?line=warm&purpose=manicure')
            ->assertOk()
            ->assertSee('Ничего не нашлось');
    }

    /**
     * Карточка товара обязана иметь собственный адрес: в прототипе она
     * открывалась модалкой поверх /catalog (docs/07-design-review.md §4.1).
     */
    public function test_product_page_has_its_own_url(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        $this->get('/product/' . $product->slug)
            ->assertOk()
            ->assertSee($product->title)
            ->assertSee('180 мл')
            ->assertSee('540 мл');
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->get('/product/net-takogo-tovara')->assertNotFound();
    }

    /** Рейтинг нельзя показывать без указания площадки-источника (§8.1). */
    public function test_rating_is_always_shown_with_its_source(): void
    {
        $this->get('/product/multipenka-dlya-ruk-i-stop')
            ->assertOk()
            ->assertSee('отзывов на Ozon');
    }

    public function test_out_of_stock_product_is_visible_but_not_buyable(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        foreach ($product->variants as $variant) {
            $variant->quota->update(['allocated' => 0]);
        }

        $this->get('/product/' . $product->slug)
            ->assertOk()
            ->assertSee('Нет в наличии');
    }

    public function test_money_is_formatted_from_kopecks(): void
    {
        $this->assertSame("450\u{00A0}₽", Money::rub(45000));
        $this->assertSame("1 190\u{00A0}₽", Money::rub(119000));
        $this->assertSame('—', Money::rub(null));
    }

    private function productCount(string $html): int
    {
        preg_match_all('~/product/([a-z0-9-]+)~', $html, $matches);

        return count(array_unique($matches[1]));
    }
}
