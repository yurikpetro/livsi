<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockQuota;
use App\Seo\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Обновление 16: сайт сам сообщает Яндексу об изменениях (IndexNow), отдаёт
 * фид товаров для блока «Товары» и заголовки товаров с брендом.
 */
class SeoFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'https://livsi.shop']);
    }

    private function product(): Product
    {
        $line = ProductLine::create(['code' => 'fresh', 'title' => 'Fresh', 'is_active' => true, 'sort' => 1]);
        $product = Product::create(['slug' => 'multipenka-dlya-manikyura-fresh', 'title' => 'Мультипенка для маникюра', 'short_description' => 'Снимает кутикулу за минуту.',
            'product_line_id' => $line->id, 'is_active' => true, 'sort' => 1]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LV-MF200', 'barcode' => '4870216352462', 'option_volume' => '200 мл',
            'volume_ml' => 200, 'price' => 89000, 'compare_at_price' => 99000, 'weight_g' => 250, 'is_default' => true, 'is_active' => true]);
        StockQuota::create(['product_variant_id' => $variant->id, 'allocated' => 5, 'reserved' => 0, 'sold' => 1]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'img/catalog/fresh-hero.jpg', 'is_primary' => true]);

        return $product;
    }

    public function test_feed_lists_variants_with_price_stock_and_brand(): void
    {
        $product = $this->product();
        Product::create(['slug' => 'skrytyy', 'title' => 'Скрытый', 'is_active' => false, 'sort' => 2]);

        $xml = simplexml_load_string($this->get('/yml.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent());

        $this->assertSame('LIVSI', (string) $xml->shop->name);
        $this->assertCount(1, $xml->shop->offers->offer);
        $offer = $xml->shop->offers->offer[0];
        $this->assertSame('true', (string) $offer['available']);
        $this->assertSame('Мультипенка для маникюра LIVSI, 200 мл', (string) $offer->name);
        $this->assertSame(['890', '990', 'RUR', 'LIVSI'], [(string) $offer->price, (string) $offer->oldprice, (string) $offer->currencyId, (string) $offer->vendor]);
        $this->assertSame(route('catalog.show', $product), (string) $offer->url);
        $this->assertSame('https://livsi.shop/img/catalog/fresh-hero.jpg', (string) $offer->picture);
        $this->assertSame('4870216352462', (string) $offer->barcode);
        $this->assertSame('Fresh', (string) $xml->shop->categories->category[1]);
    }

    public function test_product_page_title_names_the_brand_and_the_official_shop(): void
    {
        $product = $this->product();

        $this->get(route('catalog.show', $product))->assertOk()
            ->assertSee('<title>Мультипенка для маникюра LIVSI — купить в официальном магазине</title>', false)
            ->assertSee('Официальный магазин LIVSI, доставка по всей России.', false)
            ->assertSee('LIVSI (ЛИВСИ)', false);
        $product->update(['seo_title' => 'Своя строка']);
        $this->get(route('catalog.show', $product))->assertSee('<title>Своя строка</title>', false);
    }

    public function test_changed_pages_go_to_yandex_in_one_batch(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['yandex.com/indexnow' => Http::response('', 202)]);

        $product = $this->product();
        $product->update(['title' => 'Мультипенка для маникюра Fresh']);
        $this->get('/indexnow.txt')->assertOk()->assertSee(IndexNow::key());

        $this->artisan('seo:indexnow')->expectsOutputToContain('ответ Яндекса: 202 (принято)')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === IndexNow::ENDPOINT
            && $request['host'] === 'livsi.shop' && $request['key'] === IndexNow::key()
            && $request['keyLocation'] === 'https://livsi.shop/indexnow.txt'
            && in_array('https://livsi.shop/product/multipenka-dlya-manikyura-fresh', $request['urlList'], true)
            && in_array('https://livsi.shop/line/fresh', $request['urlList'], true)
            && count($request['urlList']) === count(array_unique($request['urlList'])));
        // Очередь опустела — повторный запуск ничего не шлёт.
        $this->artisan('seo:indexnow')->expectsOutputToContain('Новых изменений нет')->assertSuccessful();
        Http::assertSentCount(1);

        // Весь сайт разом — после установки.
        $this->artisan('seo:indexnow --all')->assertSuccessful();
        Http::assertSent(fn (Request $request) => in_array('https://livsi.shop/', $request['urlList'] ?? [], true)
            || in_array('https://livsi.shop', $request['urlList'] ?? [], true));
    }

    public function test_copies_and_development_do_not_bother_yandex(): void
    {
        $this->product()->update(['title' => 'Новое']);
        $this->assertNull(Cache::get('indexnow:pending'));
        $this->artisan('seo:indexnow')->expectsOutputToContain('IndexNow выключен')->assertSuccessful();

        $this->app['env'] = 'production';
        config(['app.url' => 'http://127.0.0.1:8002']);
        $this->assertFalse(IndexNow::enabled());
        config(['app.url' => 'https://livsi.shop']);
        Setting::put('indexnow_enabled', false);
        $this->assertFalse(IndexNow::enabled());
        Http::assertNothingSent();
    }
}
