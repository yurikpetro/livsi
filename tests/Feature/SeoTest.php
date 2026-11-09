<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Чтобы по запросу «livsi» Яндекс показывал официальный сайт: одно зеркало,
 * карта сайта в robots.txt, разметка бренда на главной, код Вебмастера.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://livsi.shop']);
    }

    public function test_www_mirror_moves_permanently_to_the_main_address(): void
    {
        $this->get('http://www.livsi.shop/catalog?line=multi')->assertStatus(301)
            ->assertRedirect('https://livsi.shop/catalog?line=multi');
        // Формы, вебхуки и чужие адреса (разработка, копия сервера) не трогаем.
        $this->post('http://www.livsi.shop/webhooks/tochka', [])->assertStatus(200);
        $this->get('http://www.livsi.shop/up')->assertOk();
        $this->get('http://127.0.0.1/contacts')->assertOk();
    }

    public function test_robots_points_to_the_sitemap_and_hides_service_pages(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://livsi.shop/sitemap.xml', false)
            ->assertSee('Disallow: /checkout', false)->assertSee('Disallow: /order/', false)
            ->assertSee('Clean-param: utm_source&utm_medium', false);

        $dir = sys_get_temp_dir().'/livsi-public-'.uniqid();
        mkdir($dir);
        $this->app->usePublicPath($dir);
        $this->artisan('seo:robots')->assertSuccessful();
        $this->assertStringContainsString('Sitemap: https://livsi.shop/sitemap.xml', file_get_contents($dir.'/robots.txt'));
    }

    public function test_home_page_says_it_is_the_official_livsi_site(): void
    {
        Setting::put('vk_url', 'https://vk.com/livsi');
        Setting::put('yandex_verification', 'abc123DEF');

        $page = $this->get('/')->assertOk()
            ->assertSee('<title>LIVSI — официальный интернет-магазин косметики LIVSI PROFESSIONAL</title>', false)
            ->assertSee('<meta name="yandex-verification" content="abc123DEF">', false)
            ->assertSee('https://vk.com/livsi', false);
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $page->getContent(), $match);
        $schema = json_decode($match[1] ?? '', true);
        $this->assertSame('LIVSI', $schema['@graph'][0]['name']);
        $this->assertContains('ЛИВСИ', $schema['@graph'][0]['alternateName']);
        $this->assertSame(['https://vk.com/livsi'], $schema['@graph'][0]['sameAs']);
        $this->assertSame('https://livsi.shop/', $schema['@graph'][1]['url']);

        // На остальных страницах кода Вебмастера достаточно, разметки бренда нет.
        $this->get('/contacts')->assertOk()->assertSee('yandex-verification', false)->assertDontSee('"@graph"', false);
    }
}
