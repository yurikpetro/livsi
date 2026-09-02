<?php

namespace Tests\Feature;

use App\Livewire\QuickView;
use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Быстрый просмотр товара из листинга.
 *
 * Он именно в дополнение к странице товара, а не вместо: у страницы свой
 * адрес, микроразметка и место в карте сайта. Модалка нужна, чтобы выбрать
 * объём и положить в корзину, не уходя из выдачи.
 */
class QuickViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_modal_is_closed_by_default(): void
    {
        Livewire::test(QuickView::class)->assertSet('open', false);
    }

    public function test_event_opens_the_modal_with_the_product(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('open', true)
            ->assertSee($product->title)
            ->assertSee('Открыть полную карточку');
    }

    public function test_default_variant_is_preselected(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->variants->firstWhere('is_default', true);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', $default->id);
    }

    /** Если вариант по умолчанию распродан, предвыбираем первый доступный. */
    public function test_sold_out_default_variant_falls_back_to_an_available_one(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->variants->firstWhere('is_default', true);
        $other   = $product->variants->firstWhere('is_default', false);

        $default->quota->update(['allocated' => 0]);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', $other->id);
    }

    public function test_variant_can_be_switched(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $other   = $product->variants->firstWhere('is_default', false);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->call('selectVariant', $other->id)
            ->assertSet('variantId', $other->id);
    }

    /** Вариант чужого товара подсунуть нельзя. */
    public function test_variant_of_another_product_is_ignored(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $foreign = Product::with('variants')->where('slug', 'skrab-dlya-tela')->firstOrFail()->variants->first();

        $component = Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id);
        $before    = $component->get('variantId');

        $component->call('selectVariant', $foreign->id)->assertSet('variantId', $before);
    }

    /**
     * Кладём в корзину и закрываем модалку: иначе поверх быстрого просмотра
     * открылась бы выдвижная корзина, и вышло бы два слоя друг на друге.
     */
    public function test_adding_to_cart_closes_the_modal_and_notifies_the_cart(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $other   = $product->variants->firstWhere('is_default', false);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->call('selectVariant', $other->id)
            ->call('addToCart')
            ->assertSet('open', false)
            ->assertDispatched('cart-add', variantId: $other->id);
    }

    public function test_unknown_product_does_not_open_the_modal(): void
    {
        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: 999999)
            ->assertSet('open', false);
    }

    public function test_inactive_product_does_not_open_the_modal(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->update(['is_active' => false]);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('open', false);
    }

    public function test_sold_out_product_cannot_be_added(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'skrab-dlya-tela')->firstOrFail();

        foreach ($product->variants as $variant) {
            $variant->quota->update(['allocated' => 0]);
        }

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', null)
            ->assertSee('Нет в наличии')
            ->call('addToCart')
            ->assertNotDispatched('cart-add');
    }

    public function test_catalog_page_renders_quick_view_buttons(): void
    {
        $html = $this->get('/catalog')->assertOk()->getContent();

        $this->assertStringContainsString('Быстрый просмотр', $html);
        $this->assertStringContainsString("Livewire.dispatch('quick-view'", $html);
    }

    /** Кнопка быстрого просмотра не должна лежать внутри ссылки на товар. */
    public function test_quick_view_button_is_not_nested_in_the_product_link(): void
    {
        $html = $this->get('/catalog')->assertOk()->getContent();

        $position = strpos($html, 'Быстрый просмотр');
        $before   = substr($html, 0, $position);

        // Между началом ссылки на товар и кнопкой обязательно есть её закрытие.
        $this->assertGreaterThan(
            strrpos($before, '<a href') ?: 0,
            strrpos($before, '</a>') ?: 0,
            'Кнопка быстрого просмотра оказалась внутри ссылки — это ломает клавиатуру и разметку',
        );
    }
}
