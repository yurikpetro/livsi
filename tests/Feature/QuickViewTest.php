<?php

namespace Tests\Feature;

use App\Livewire\QuickView;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Money;
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
            ->assertSee('Состав · применение · отзывы');
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

    /**
     * Содержимое модалки — как в макете.
     *
     * Объём выбирается, ниже три факта: аромат, эффект и порог бесплатной
     * доставки. Подробное — состав, применение — спрятано за разворотом,
     * чтобы быстрый просмотр оставался быстрым.
     */
    public function test_modal_shows_what_the_mockup_shows(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('Объём')
            ->assertSee('Аромат')
            ->assertSee('Эффект')
            ->assertSee('Доставка')
            ->assertSee('Добавить в корзину')
            ->assertSee('Состав · применение · отзывы');
    }

    /**
     * Строка «Состав · применение · отзывы» — ссылка на страницу товара.
     *
     * Раньше она раскрывалась разворотом с копией текста. Копия означала
     * два места для одного и того же; к тому же на странице товара этот
     * текст идёт вместе с документами и микроразметкой.
     */
    public function test_details_row_leads_to_the_product_page(): void
    {
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        $html = Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->html();

        $marker = (int) strpos($html, 'modal-more');
        $start  = (int) strrpos(substr($html, 0, $marker), '<');
        $tag    = substr($html, $start, $marker - $start);

        $this->assertStringStartsWith('<a ', $tag, 'Строка перестала быть ссылкой');
        $this->assertStringContainsString(route('catalog.show', $product), $tag);
        $this->assertStringNotContainsString('<details', $html, 'Разворот вернулся: текст снова дублируется');
    }

    /** Порог берётся из настроек: заказчик меняет его сам. */
    public function test_delivery_threshold_comes_from_settings(): void
    {
        Setting::put('free_shipping_threshold', 250000);

        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        Livewire::test(QuickView::class)
            ->dispatch('quick-view', productId: $product->id)
            ->assertSee('Бесплатно в заказе от ' . Money::rub(250000));
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
        $start  = (int) strrpos(substr($html, 0, $marker), '<');
        $tag    = substr($html, $start, $marker - $start);

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
        $before   = substr($html, 0, $position);

        // Между началом ссылки на товар и кнопкой обязательно есть её закрытие.
        $this->assertGreaterThan(
            strrpos($before, '<a href') ?: 0,
            strrpos($before, '</a>') ?: 0,
            'Кнопка быстрого просмотра оказалась внутри ссылки — это ломает клавиатуру и разметку',
        );
    }
}
