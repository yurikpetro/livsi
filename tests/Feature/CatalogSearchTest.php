<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_search_finds_products_by_title(): void
    {
        // Три мультипенки: для рук и стоп плюс две для маникюра.
        $this->assertSame(3, $this->found('пенка'));
    }

    /**
     * Главная причина, по которой поисковый текст нормализуется в PHP:
     * SQLite приводит регистр только для ASCII, «ПЕНКА» иначе не нашлась бы.
     */
    public function test_search_is_case_insensitive_for_cyrillic(): void
    {
        $this->assertSame($this->found('пенка'), $this->found('ПЕНКА'));
        $this->assertSame($this->found('пенка'), $this->found('ПеНкА'));
    }

    public function test_search_finds_products_by_aroma(): void
    {
        $this->assertGreaterThan(0, $this->found('вишня'));
    }

    public function test_search_finds_products_by_purpose_title(): void
    {
        // «Маникюр» — название назначения, а не слово из карточки.
        $this->assertGreaterThan(0, $this->found('маникюр'));
    }

    public function test_search_finds_product_by_variant_sku(): void
    {
        $variant = Product::where('slug', 'skrab-dlya-tela')->firstOrFail()->variants()->firstOrFail();

        $this->get('/catalog?q=' . $variant->sku)
            ->assertOk()
            ->assertSee('Скраб для тела');
    }

    /** Каждое слово запроса должно найтись — иначе выдача превращается в свалку. */
    public function test_multiple_words_narrow_the_result(): void
    {
        $wide   = $this->found('пенка');
        $narrow = $this->found('пенка маникюр');

        $this->assertGreaterThan($narrow, $wide);
        $this->assertGreaterThan(0, $narrow);
    }

    public function test_search_without_matches_shows_empty_state(): void
    {
        $this->get('/catalog?q=' . urlencode('шампунь для тракторов'))
            ->assertOk()
            ->assertSee('Ничего не нашлось');
    }

    public function test_single_letter_query_is_ignored(): void
    {
        // Одна буква дала бы почти весь каталог — такие термины отбрасываются.
        $this->assertSame($this->found(''), $this->found('а'));
    }

    /**
     * Битый UTF-8 приходит от ботов и клиентов со сломанной кодировкой.
     * На нём падают и json_encode при сериализации Livewire, и preg с /u.
     */
    public function test_malformed_utf8_query_does_not_break_the_page(): void
    {
        $this->get('/catalog?q=%D0%BF%FF%FE%BA%D0%B0')->assertOk();
    }

    public function test_search_query_is_shown_on_the_page(): void
    {
        $this->get('/catalog?q=' . urlencode('скраб'))
            ->assertOk()
            ->assertSee('скраб');
    }

    /** Изменение назначений товара должно попадать в поисковый текст. */
    public function test_search_text_is_rebuilt_when_relations_change(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        $this->assertStringNotContainsString('маникюр', (string) $product->search_text);

        $product->purposes()->attach(\App\Models\Purpose::where('code', 'manicure')->firstOrFail());
        $product->rebuildSearchText();

        $this->assertStringContainsString('маникюр', (string) $product->fresh()->search_text);
    }

    private function found(string $q): int
    {
        $html = $this->get('/catalog?q=' . urlencode($q))->assertOk()->getContent();

        preg_match_all('~/product/([a-z0-9-]+)~', $html, $matches);

        return count(array_unique($matches[1]));
    }
}
