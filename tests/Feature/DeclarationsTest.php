<?php

namespace Tests\Feature;

use App\Models\Declaration;
use App\Models\Product;
use App\Services\CatalogQuery;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Выпадающее меню каталога и декларации соответствия.
 *
 * Оба пункта были пропущены при разборе прототипа: в дереве доступности
 * «Каталог» значился кнопкой, а не ссылкой — признак раскрывающегося меню,
 * который я не проверил наведением.
 */
class DeclarationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    // ─────────────────────────────────── выпадающее меню

    public function test_catalog_dropdown_has_all_items_from_the_prototype(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Все товары', 'Новинки', 'Наборы', 'Уход', 'PRO', 'Декларации'] as $item) {
            $this->assertStringContainsString('>' . $item . '<', $html, "В меню нет пункта «{$item}»");
        }
    }

    public function test_catalog_dropdown_items_lead_to_working_pages(): void
    {
        foreach ([
            route('catalog.index'),
            route('catalog.index', ['tab' => 'new']),
            route('catalog.index', ['tab' => 'bundles']),
            route('catalog.index', ['tab' => 'care']),
            route('catalog.pro'),
            route('declarations'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** «Уход» раскрывает ароматические линейки — как в макете. */
    public function test_care_submenu_lists_the_aromatic_lines(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="catalog-sub-care"', $html);

        foreach (['FRESH', 'SWEET', 'WARM', 'BASE'] as $line) {
            $this->assertStringContainsString($line, $html);
        }

        // Пункты подменю ведут на лендинги линеек, а не на фильтр каталога.
        foreach (['fresh', 'sweet', 'warm', 'base'] as $code) {
            $this->assertStringContainsString(route('catalog.line', $code), $html);
        }
    }

    /** PRO раскрывает свои подкатегории. */
    public function test_pro_submenu_lists_its_subcategories(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="catalog-sub-pro"', $html);
        $this->assertStringContainsString('SKIN', $html);
        $this->assertStringContainsString('MANICURE / PEDICURE', $html);
    }

    public function test_submenu_links_open_working_pages(): void
    {
        foreach ([
            route('catalog.line', 'fresh'),
            route('catalog.line', 'base'),
            route('catalog.index', ['tab' => 'pro', 'purpose' => ['body', 'face']]),
            route('catalog.index', ['tab' => 'pro', 'purpose' => ['manicure', 'pedicure']]),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** Маркеры подменю окрашены цветами линеек из брендбука. */
    public function test_submenu_markers_use_brandbook_colours(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['#d0dd56', '#f6accd', '#ffe405', '#f5e2b7', '#99d0f7'] as $colour) {
            $this->assertStringContainsString('background: ' . $colour, $html);
        }
    }

    /** Сам пункт остаётся ссылкой, иначе без JavaScript каталог недоступен. */
    public function test_catalog_menu_trigger_is_a_link(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-controls="catalog-menu"', escape: false);
    }

    /**
     * «Уход» читаем как «отдельные средства для дома»: не набор и не PRO.
     * Толкование требует подтверждения заказчиком.
     */
    public function test_care_tab_excludes_bundles_and_pro(): void
    {
        $products = CatalogQuery::fromRequest(Request::create('/catalog', 'GET', ['tab' => 'care']))
            ->builder()->get();

        $this->assertGreaterThan(0, $products->count());
        $this->assertTrue($products->every(fn (Product $p) => ! $p->is_pro && ! $p->is_bundle));
    }

    // ─────────────────────────────────── страница деклараций

    public function test_declarations_page_shows_honest_empty_state(): void
    {
        $this->assertSame(0, Declaration::count());

        $this->get('/declarations')
            ->assertOk()
            ->assertSee('Документы готовятся к публикации');
    }

    public function test_declarations_page_lists_documents(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        $declaration = Declaration::create([
            'number'      => 'ЕАЭС N RU Д-RU.РА01.В.12345/26',
            'title'       => 'Косметика для тела',
            'issued_on'   => '2026-01-15',
            'valid_until' => '2031-01-14',
            'file_path'   => 'declarations/test.pdf',
            'file_size'   => 524288,
        ]);

        $declaration->products()->attach($product);

        $this->get('/declarations')
            ->assertOk()
            ->assertSee('ЕАЭС N RU Д-RU.РА01.В.12345/26')
            ->assertSee('Косметика для тела')
            ->assertSee('Скраб для тела')
            ->assertSee('до 14.01.2031')
            ->assertSee('Скачать PDF')
            ->assertSee('512 КБ');
    }

    /** Истёкшую декларацию нельзя выдавать за действующую. */
    public function test_expired_declaration_is_marked(): void
    {
        Declaration::create([
            'number'      => 'ЕАЭС N RU Д-RU.РА01.В.00001/20',
            'valid_until' => now()->subMonth()->toDateString(),
        ]);

        $this->get('/declarations')->assertOk()->assertSee('Срок истёк');
    }

    public function test_declaration_without_file_says_so(): void
    {
        Declaration::create(['number' => 'ЕАЭС N RU Д-RU.РА01.В.00002/26']);

        $this->get('/declarations')
            ->assertOk()
            ->assertSee('Файл не загружен')
            ->assertDontSee('Скачать PDF');
    }

    public function test_inactive_declaration_is_hidden(): void
    {
        Declaration::create(['number' => 'СКРЫТАЯ', 'is_active' => false]);

        $this->get('/declarations')->assertOk()->assertDontSee('СКРЫТАЯ');
    }

    // ─────────────────────────────────── связь с товаром

    public function test_product_page_shows_its_declarations(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        $declaration = Declaration::create([
            'number'      => 'ЕАЭС N RU Д-RU.РА01.В.55555/26',
            'valid_until' => '2031-06-30',
            'file_path'   => 'declarations/test.pdf',
        ]);

        $declaration->products()->attach($product);

        $this->get('/product/' . $product->slug)
            ->assertOk()
            ->assertSee('Документы')
            ->assertSee('ЕАЭС N RU Д-RU.РА01.В.55555/26')
            ->assertSee('Все декларации');
    }

    public function test_product_without_declarations_has_no_documents_block(): void
    {
        $this->get('/product/skrab-dlya-tela')
            ->assertOk()
            ->assertDontSee('>Документы<', escape: false);
    }

    public function test_sitemap_includes_the_declarations_page(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('declarations'));
    }

    public function test_footer_links_to_declarations(): void
    {
        $this->get('/')->assertOk()->assertSee('Декларации соответствия');
    }
}
