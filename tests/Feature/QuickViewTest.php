<?php

namespace Tests\Feature;

use App\Livewire\QuickView;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Быстрый просмотр товара из листинга.
 *
 * Галерея, подробности и изменение корзины без перехода со страницы.
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
            ->assertDontSee('Полная карточка и отзывы');
    }

    /** На телефоне рамка фото берёт пропорции снимка — фото не обрезается. */
    public function test_photo_frame_takes_the_photo_proportions(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $path = 'img/test-quick-view-ratio.png';
        imagepng(imagecreatetruecolor(400, 500), public_path($path));
        $product->images()->delete();
        $product->images()->create(['path' => $path, 'alt' => 'Фото', 'sort' => 0]);

        try {
            Livewire::test(QuickView::class)
                ->dispatch('quick-view', productId: $product->id)
                ->assertSeeHtml('style="--photo-ratio: 400 / 500"');
        } finally {
            @unlink(public_path($path));
        }
    }

    public function test_default_variant_is_preselected(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->variants->firstWhere('is_default', true);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', $default->id);
    }

    public function test_description_is_visible_and_escaped_without_internal_sku(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $product->update([
            'sku' => 'PRIVATE-PRODUCT-001',
            'active_ingredients' => 'Описание <script>alert("xss")</script>',
        ]);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('Описание <script>alert("xss")</script>')
            ->assertDontSee('<script>alert("xss")</script>', escape: false)
            ->assertDontSee('PRIVATE-PRODUCT-001');
    }

    public function test_empty_description_section_is_not_shown(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $product->update(['active_ingredients' => null]);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertDontSee('<h3>Описание</h3>', escape: false);
    }

    /** Если вариант по умолчанию распродан, предвыбираем первый доступный. */
    public function test_sold_out_default_variant_falls_back_to_an_available_one(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->variants->firstWhere('is_default', true);
        $other = $product->variants->firstWhere('is_default', false);

        $default->quota->update(['allocated' => 0]);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', $other->id);
    }

    public function test_variant_can_be_switched(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $other = $product->variants->firstWhere('is_default', false);

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
        $before = $component->get('variantId');

        $component->call('selectVariant', $foreign->id)->assertSet('variantId', $before);
    }

    public function test_adding_to_cart_keeps_the_modal_open_and_notifies_the_cart(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $other = $product->variants->firstWhere('is_default', false);

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->call('selectVariant', $other->id)
            ->call('addToCart')
            ->assertSet('open', true)
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

    public function test_modal_shows_what_the_mockup_shows(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('Объём')
            ->assertSee('Аромат')
            ->assertSee('Эффект')
            ->assertDontSee('Доставка')
            ->assertSee('Добавить в корзину')
            ->assertSee('С этим товаром рекомендуем')
            ->assertDontSee('Полная карточка и отзывы');
    }

    public function test_composition_and_application_are_visible_without_expanding_details(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        $html = Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->html();

        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString(route('catalog.show', $product), $html);
        $this->assertStringContainsString(e($product->composition), $html);
        $this->assertStringContainsString(e($product->application), $html);
        $this->assertStringContainsString('Применение', $html);
    }

    public function test_volume_is_shown_even_for_a_product_with_one_variant(): void
    {
        $product = Product::with('variants')->where('slug', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('Объём')
            ->assertSee($product->defaultVariant()->option_volume);
    }

    public function test_inactive_default_variant_falls_back_to_an_active_one(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->defaultVariant();
        $other = $product->variants->firstWhere('is_default', false);
        $default->update(['is_active' => false]);

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id)
            ->assertSet('variantId', $other->id)
            ->call('selectVariant', $default->id)
            ->assertSet('variantId', $other->id);
    }

    public function test_sold_out_variant_cannot_be_selected(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $default = $product->defaultVariant();
        $other = $product->variants->firstWhere('is_default', false);
        $other->quota->update(['allocated' => 0]);

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id)
            ->call('selectVariant', $other->id)
            ->assertSet('variantId', $default->id);
    }

    public function test_tampered_variant_from_another_product_is_not_added(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $foreign = Product::with('variants')->where('slug', 'skrab-dlya-tela')->firstOrFail()->defaultVariant();

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id)
            ->set('variantId', $foreign->id)->call('addToCart')
            ->assertNotDispatched('cart-add')
            ->assertDispatched('cart-notice', message: 'Этот вариант сейчас недоступен');
    }

    public function test_variant_that_sells_out_after_opening_is_not_added(): void
    {
        $product = Product::with('variants.quota')->where('slug', 'skrab-dlya-tela')->firstOrFail();
        $component = Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id);
        $product->defaultVariant()->quota->update(['allocated' => 0]);

        $component->call('addToCart')->assertSet('open', true)
            ->assertNotDispatched('cart-add')
            ->assertDispatched('cart-notice', message: 'Этот вариант сейчас недоступен');
    }

    public function test_modal_initializes_the_paid_quantity_without_counting_a_gift(): void
    {
        $product = Product::with('variants')->where('slug', 'skrab-dlya-tela')->firstOrFail();
        $cart = Cart::create(['token' => str_repeat('q', 48)]);
        $cart->items()->create(['product_variant_id' => $product->defaultVariant()->id, 'qty' => 2]);
        $cart->items()->create(['product_variant_id' => $product->defaultVariant()->id, 'qty' => 1, 'is_gift' => true]);

        Livewire::withCookie(CartService::COOKIE, $cart->token)->test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('data-quantity="2"', false)
            ->assertSee('Количество товара Скраб для тела');
    }

    public function test_variant_deactivated_after_opening_is_not_added(): void
    {
        $product = Product::with('variants')->where('slug', 'skrab-dlya-tela')->firstOrFail();
        $component = Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id);
        $product->defaultVariant()->update(['is_active' => false]);

        $component->call('addToCart')->assertNotDispatched('cart-add')
            ->assertDispatched('cart-notice', message: 'Этот вариант сейчас недоступен');
    }

    public function test_opening_the_modal_does_not_create_an_empty_cart(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id);

        $this->assertSame(0, Cart::count());
    }

    /** В выборе стоит только объём: аромат показан отдельной строкой. */
    public function test_choice_shows_the_volume_only(): void
    {
        $product = Product::with('variants')->where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $variant = $product->variants->first();

        $variant->update(['option_volume' => '180 мл', 'option_aroma' => 'грейпфрут']);

        $html = Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->html();

        $this->assertStringContainsString('180 мл', $html);
        $this->assertStringNotContainsString(
            '180 мл · грейпфрут',
            $html,
            'В выборе объёма склеился аромат — он показывается отдельной строкой',
        );
    }

    /**
     * Фотография на карточке — кнопка быстрого просмотра, как в прототипе.
     *
     * Раньше снимок был ссылкой на страницу товара, а просмотр висел
     * отдельной маленькой кнопкой. Теперь наведение показывает подпись,
     * а страница товара открывается по заголовку: два действия не спорят
     * за один клик.
     */
    public function test_card_photo_opens_the_quick_view_not_the_product_page(): void
    {
        $html = $this->get('/catalog')->assertOk()->getContent();

        // Берём открывающий тег фотографии целиком: обработчик стоит в нём
        // раньше класса, поэтому окно отсчитываем от начала тега.
        $marker = (int) strpos($html, 'product-open');
        $start = (int) strrpos(substr($html, 0, $marker), '<');
        $tag = substr($html, $start, $marker - $start);

        $this->assertStringStartsWith('<button', $tag, 'Фотография снова стала ссылкой на страницу товара');
        $this->assertStringContainsString("Livewire.dispatch('quick-view'", $tag);
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
        $before = substr($html, 0, $position);

        // Между началом ссылки на товар и кнопкой обязательно есть её закрытие.
        $this->assertGreaterThan(
            strrpos($before, '<a href') ?: 0,
            strrpos($before, '</a>') ?: 0,
            'Кнопка быстрого просмотра оказалась внутри ссылки — это ломает клавиатуру и разметку',
        );
    }
}
