<?php

namespace Tests\Feature;

use App\Livewire\QuickView;
use App\Models\Product;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_stock_of_an_inactive_variant_does_not_enable_purchasing(): void
    {
        $product = $this->singleVariantProduct();
        $variant = $product->defaultVariant();
        $variant->update(['is_active' => false, 'price' => 35000]);
        $variant->quota->update(['allocated' => 100, 'reserved' => 0, 'sold' => 1]);
        $product->refresh();

        $this->assertFalse($product->isAvailable());
        $this->assertNull($product->priceFrom());
        $html = $this->card($product);
        $this->assertStringContainsString('Нет в наличии', $html);
        $this->assertStringNotContainsString('data-cart-card', $html);
        $this->assertStringNotContainsString(Money::rub(35000), $html);

        Livewire::test(QuickView::class)->call('show', $product->id)
            ->assertSet('variantId', null)
            ->assertDontSee(Money::rub(35000))
            ->call('addToCart')->assertNotDispatched('cart-add');

        $this->get(route('catalog.show', $product))->assertOk()
            ->assertSee('Нет в наличии')->assertDontSee(Money::rub(35000));
    }

    public function test_enabling_a_stocked_variant_restores_price_and_purchasing_everywhere(): void
    {
        $product = $this->singleVariantProduct();
        $variant = $product->defaultVariant();
        $variant->update(['is_active' => false, 'is_default' => false, 'price' => 35000]);
        $variant->quota->update(['allocated' => 100, 'reserved' => 0, 'sold' => 1]);
        $variant->update(['is_active' => true]);
        $product->refresh();

        $html = $this->card($product);
        $this->assertStringContainsString(Money::rub(35000), $html);
        $this->assertStringContainsString('data-variant-id="'.$variant->id.'"', $html);
        $this->assertStringNotContainsString('Нет в наличии', $html);

        Livewire::test(QuickView::class)->call('show', $product->id)
            ->assertSet('variantId', $variant->id)->assertSee(Money::rub(35000))
            ->call('addToCart')->assertDispatched('cart-add', variantId: $variant->id);

        $this->get(route('catalog.show', $product))->assertOk()
            ->assertSee(Money::rub(35000))->assertSee('Добавить в корзину')
            ->assertDontSee('Нет в наличии');
    }

    #[DataProvider('unavailableDefaults')]
    public function test_all_storefronts_choose_a_buyable_variant_when_the_default_is_unavailable(string $reason): void
    {
        $product = Product::with('variants.quota')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->defaultVariant();
        $other = $product->variants->firstWhere('is_default', false);

        match ($reason) {
            'inactive' => $default->update(['is_active' => false]),
            'sold-out' => $default->quota->update(['allocated' => 0]),
            'no-quota' => $default->quota->delete(),
        };
        $product->refresh();

        $html = $this->card($product);
        $this->assertStringContainsString('data-variant-id="'.$other->id.'"', $html);
        $this->assertStringContainsString('name="variant_id" value="'.$other->id.'"', $html);
        $this->assertStringNotContainsString('Нет в наличии', $html);

        Livewire::test(QuickView::class)->call('show', $product->id)
            ->assertSet('variantId', $other->id);

        $html = $this->get(route('catalog.show', $product))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $selected = $xpath->query('//input[@name="variant_id" and @checked]');
        $this->assertSame(1, $selected->length);
        $this->assertSame((string) $other->id, $selected->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//input[@name="variant_id" and @value="'.$default->id.'" and @disabled]')->length);
    }

    /** @return array<string, array{string}> */
    public static function unavailableDefaults(): array
    {
        return [
            'inactive' => ['inactive'],
            'sold out' => ['sold-out'],
            'without quota' => ['no-quota'],
        ];
    }

    public function test_sold_out_active_variant_keeps_its_price_but_cannot_be_bought(): void
    {
        $product = $this->singleVariantProduct();
        $variant = $product->defaultVariant();
        $variant->quota->update(['allocated' => 3, 'reserved' => 2, 'sold' => 1]);
        $product->refresh();

        $html = $this->card($product);
        $this->assertStringContainsString(Money::rub($variant->price), $html);
        $this->assertStringContainsString('Нет в наличии', $html);
        $this->assertStringNotContainsString('data-cart-card', $html);

        Livewire::test(QuickView::class)->call('show', $product->id)
            ->assertSet('variantId', null)->assertSee(Money::rub($variant->price))
            ->call('addToCart')->assertNotDispatched('cart-add');
    }

    public function test_buyable_default_remains_preferred_over_other_buyable_variants(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->defaultVariant();
        $default->update(['sort' => 100]);
        $product->refresh();

        $this->assertStringContainsString('data-variant-id="'.$default->id.'"', $this->card($product));
        Livewire::test(QuickView::class)->call('show', $product->id)->assertSet('variantId', $default->id);
    }

    private function singleVariantProduct(): Product
    {
        $product = Product::with('variants.quota')->where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->update(['auto_recommendations' => false, 'recommended_product_ids' => []]);

        return $product;
    }

    private function card(Product $product): string
    {
        return Blade::render('<x-product-card :product="$product" />', compact('product'));
    }
}
