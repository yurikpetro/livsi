<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\MissingPage;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\SeoRedirect;
use App\Models\User;
use App\Seo\MissingPages;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Обновление 18.2: значок сайта, заголовки товаров с ароматом, адреса
 * товаров без «…multipenkaаа» и переадресация при смене адреса, «Битые
 * ссылки» без сканеров.
 */
class SeoProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://livsi.shop']);
    }

    // ------------------------------------------------- значок

    public function test_square_favicon_in_every_size_yandex_and_browsers_ask_for(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/favicon\.svg" type="image/svg\+xml">#', $html);
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/favicon\.ico" sizes="48x48">#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/favicon-120\.png" type="image/png" sizes="120x120"#', $html);
        $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="[^"]*/apple-touch-icon\.png">#', $html);
        $this->assertDoesNotMatchRegularExpression('#rel="icon" href="[^"]*/img/logo\.svg"#', $html);

        // Раньше favicon.ico был пустым файлом.
        $this->assertGreaterThan(1000, filesize(public_path('favicon.ico')));
        [$w, $h] = getimagesize(public_path('favicon-120.png'));
        $this->assertSame([120, 120], [$w, $h]);
        [$w, $h] = getimagesize(public_path('apple-touch-icon.png'));
        $this->assertSame([180, 180], [$w, $h]);
        $svg = file_get_contents(public_path('favicon.svg'));
        $this->assertStringContainsString('viewBox="0 0 1000 1000"', $svg);
        $this->assertStringContainsString('#d0dd56', $svg);
    }

    // ------------------------------------------------- заголовки

    public function test_six_multifoams_get_six_different_titles_and_descriptions_with_the_aroma(): void
    {
        $fresh = ProductLine::create(['code' => 'fresh', 'title' => 'FRESH', 'sort' => 1]);
        $a = $this->product('ocishhaiushhaia-multipenka-greipfrut-zhasmin', 'Грейпфрут Жасмин', $fresh->id);
        $b = $this->product('ocishhaiushhaia-multipenka-zelenyi-mango', 'Зеленый манго', $fresh->id);

        $page = $this->get(route('catalog.show', $a))->assertOk();
        $page->assertSee('<title>Очищающая мультипенка «Грейпфрут Жасмин» LIVSI — купить в официальном магазине</title>', false);
        $page->assertSee('<meta name="description" content="Многофункциональное уходовое средство. Аромат «Грейпфрут Жасмин». Линейка FRESH. Официальный магазин LIVSI, доставка по всей России.">', false);
        $page->assertSee('"name":"Очищающая мультипенка «Грейпфрут Жасмин»"', false);
        $this->get(route('catalog.show', $b))->assertSee('<title>Очищающая мультипенка «Зеленый манго» LIVSI — купить в официальном магазине</title>', false);

        // Свой заголовок и описание из админки — главнее.
        $a->update(['seo_title' => 'Пенка для маникюра с грейпфрутом', 'seo_description' => 'Своё описание.']);
        $this->get(route('catalog.show', $a))->assertSee('<title>Пенка для маникюра с грейпфрутом</title>', false)
            ->assertSee('content="Своё описание."', false);

        // Без аромата и линейки — как раньше, но с точкой между предложениями.
        $plain = Product::create(['slug' => 'remuver-dlia-kutikuly', 'title' => 'Ремувер для кутикулы', 'short_description' => 'Размягчает плотную кутикулу', 'is_active' => true, 'sort' => 3]);
        $this->assertSame('Ремувер для кутикулы LIVSI — купить в официальном магазине', $plain->seoTitle());
        $this->assertSame('Размягчает плотную кутикулу. Официальный магазин LIVSI, доставка по всей России.', $plain->seoDescription());
    }

    // ------------------------------------------------- адреса

    public function test_changed_product_address_redirects_from_the_old_one_without_chains_or_loops(): void
    {
        $product = $this->product('ocishhaiushhaia-multipenkaаа', 'Бабл-гам');
        SeoRedirect::create(['from_path' => '/magazin/tag/ochishchenie', 'to_url' => '/product/ocishhaiushhaia-multipenkaаа']);

        $product->update(['slug' => 'ocishhaiushhaia-multipenka-babl-gam']);

        $this->get('/product/ocishhaiushhaia-multipenka%D0%B0%D0%B0')->assertStatus(301)
            ->assertRedirect('/product/ocishhaiushhaia-multipenka-babl-gam');
        // То, что вело на старый адрес, ведёт сразу на новый — без второго прыжка.
        $this->assertSame('/product/ocishhaiushhaia-multipenka-babl-gam', SeoRedirect::map()['/magazin/tag/ochishchenie']);

        // Переименовали ещё раз — обе старые ссылки ведут на последний адрес.
        $product->update(['slug' => 'multipenka-babl-gam']);
        $this->get('/product/ocishhaiushhaia-multipenka-babl-gam')->assertRedirect('/product/multipenka-babl-gam');
        $this->get('/product/ocishhaiushhaia-multipenka%D0%B0%D0%B0')->assertRedirect('/product/multipenka-babl-gam');

        // Вернули прежний адрес — он снова открывает товар, а не ведёт сам на себя.
        $product->update(['slug' => 'ocishhaiushhaia-multipenka-babl-gam']);
        $this->get('/product/ocishhaiushhaia-multipenka-babl-gam')->assertOk();
        $this->get('/product/multipenka-babl-gam')->assertRedirect('/product/ocishhaiushhaia-multipenka-babl-gam');
    }

    public function test_admin_rejects_cyrillic_and_spaces_in_the_address_and_finds_a_free_one_itself(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $product = Product::query()->where('slug', 'skrab-dlya-tela')->firstOrFail();

        foreach (['skrab-dlya-telaа', 'skrab dlya tela', 'Skrab-Dlya-Tela'] as $bad) {
            Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
                ->fillForm(['slug' => $bad])->call('save')->assertHasFormErrors(['slug' => 'regex']);
        }
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['slug' => 'skrab-dlya-tela-kokos'])->call('save')->assertHasNoFormErrors();
        $this->get('/product/skrab-dlya-tela')->assertRedirect('/product/skrab-dlya-tela-kokos');

        // Новый товар с тем же названием: адрес не дописывают руками — сайт сам даёт -2.
        $this->product('ochischayuschaya-multipenka', 'Зеленый манго');
        $this->assertSame('ochischayuschaya-multipenka-2', Product::uniqueSlug('Очищающая мультипенка'));
        Livewire::test(CreateProduct::class)
            ->fillForm(['title' => 'Очищающая мультипенка'])
            ->assertFormSet(['slug' => 'ochischayuschaya-multipenka-2']);
    }

    public function test_update_gives_the_multifoams_readable_addresses_and_cleans_scanners_out_of_broken_links(): void
    {
        $fresh = ProductLine::create(['code' => 'fresh', 'title' => 'FRESH', 'sort' => 1]);
        $p9 = $this->product('ocishhaiushhaia-multi-penka', 'Зеленый манго', $fresh->id);
        $p10 = $this->product('ocishhaiushhaia-multipenka MultiFoamGrapefruitJasmine', 'Грейпфрут Жасмин', $fresh->id);
        $p11 = $this->product('ocishhaiushhaia-multipenkaа', 'Вишня Миндаль');
        $p13 = $this->product('ocishhaiushhaia-multipenkaaaaa', 'Черный мускус');
        $alone = Product::create(['slug' => 'keratoliticeskaia-penka', 'title' => 'Кератолитическая пенка', 'is_active' => true, 'sort' => 5]);
        $broken = Product::create(['slug' => 'krem dlia tela', 'title' => 'Крем для тела', 'is_active' => true, 'sort' => 6]);
        SeoRedirect::create(['from_path' => '/magazin/tag/ochishchenie', 'to_url' => '/product/ocishhaiushhaia-multi-penka']);

        foreach (['/phpinfo', '/web.config', '/terraform.tfstate', '/api/session/properties', '/config/master.key', '/old', '/blog', '/shampuni'] as $path) {
            MissingPage::create(['path' => $path, 'hits' => 5, 'last_seen_at' => now(),
                'last_referer' => in_array($path, ['/old', '/blog'], true) ? 'http://livsi.shop'.strtoupper($path) : null]);
        }

        (require database_path('migrations/2026_11_20_180000_product_slugs_with_aroma_and_junk_cleanup.php'))->up();

        $this->assertSame('ochischayuschaya-multipenka-zelenyy-mango', $p9->fresh()->slug);
        $this->assertSame('ochischayuschaya-multipenka-greypfrut-zhasmin', $p10->fresh()->slug);
        $this->assertSame('ochischayuschaya-multipenka-vishnya-mindal', $p11->fresh()->slug);
        $this->assertMatchesRegularExpression(Product::SLUG_PATTERN, $p13->fresh()->slug);
        $this->assertSame('keratoliticeskaia-penka', $alone->fresh()->slug); // хороший адрес не трогаем
        $this->assertSame('krem-dlya-tela', $broken->fresh()->slug);

        $this->get('/product/ocishhaiushhaia-multipenka%20MultiFoamGrapefruitJasmine')->assertRedirect('/product/ochischayuschaya-multipenka-greypfrut-zhasmin');
        $this->get('/product/ocishhaiushhaia-multi-penka')->assertRedirect('/product/ochischayuschaya-multipenka-zelenyy-mango');
        $this->get('/product/krem%20dlia%20tela')->assertRedirect('/product/krem-dlya-tela');
        $this->assertSame('/product/ochischayuschaya-multipenka-zelenyy-mango', SeoRedirect::map()['/magazin/tag/ochishchenie']);
        $this->get('/product/ochischayuschaya-multipenka-greypfrut-zhasmin')->assertOk();

        $this->assertSame(['/shampuni'], MissingPage::query()->pluck('path')->all());

        // Повторный запуск ничего не меняет.
        (require database_path('migrations/2026_11_20_180000_product_slugs_with_aroma_and_junk_cleanup.php'))->up();
        $this->assertSame('ochischayuschaya-multipenka-zelenyy-mango', $p9->fresh()->slug);
    }

    public function test_scanners_are_not_written_to_broken_links_but_people_are(): void
    {
        foreach (['/phpinfo', '/_profiler/phpinfo', '/web.config', '/elmah.axd', '/server-status', '/metrics', '/app/etc/local.xml',
            '/config/master.key', '/dockerfile', '/v2/_catalog', '/credentials', '/sentry.properties', '/netlify.toml',
            '/terraform.tfstate.backup', '/api/v1/namespaces/default/secrets', '/settings.py', '/env', '/jenkinsfile', '/privkey.pem',
            '/wlwmanifest.xml', '/api/session/properties', '/wp/'] as $path) {
            $this->assertTrue(MissingPages::isJunk($path), $path);
        }
        $this->assertTrue(MissingPages::isJunk('/old', 'http://livsi.shop/old'));
        $this->assertTrue(MissingPages::isJunk('/blog', 'http://www.livsi.shop/Blog'));
        $this->assertFalse(MissingPages::isJunk('/blog', 'https://yandex.ru/search/?text=livsi'));
        $this->assertFalse(MissingPages::isJunk('/product/multipenka-dlya-manikyura-sweet', 'https://livsi.shop/'));
        $this->assertFalse(MissingPages::isJunk('/shampuni'));

        $this->get('/phpinfo')->assertNotFound();
        $this->get('/old', ['Referer' => 'http://livsi.shop/old'])->assertNotFound();
        $this->get('/shampuni', ['Referer' => 'https://yandex.ru/'])->assertNotFound();
        $this->assertSame(['/shampuni'], MissingPage::query()->pluck('path')->all());
    }

    private function product(string $slug, string $aroma, ?int $line = null): Product
    {
        static $sort = 0;

        return Product::create(['slug' => $slug, 'title' => 'Очищающая мультипенка', 'aroma' => $aroma,
            'short_description' => 'Многофункциональное уходовое средство', 'product_line_id' => $line, 'is_active' => true, 'sort' => ++$sort]);
    }
}
