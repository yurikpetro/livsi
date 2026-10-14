<?php

namespace Tests\Feature;

use App\Livewire\CartDrawer;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Services\CartService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Выдвижная корзина, как в макете. Страница /cart остаётся запасным
 * вариантом без JavaScript и покрыта отдельно в CartTest.
 */
class CartDrawerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        $this->token = Str::random(48);
        Cart::create(['token' => $this->token, 'last_activity_at' => now()]);

    }

    public function test_drawer_is_closed_by_default(): void
    {
        $this->drawer()->assertSet('open', false);
    }

    public function test_adding_a_product_reports_success_and_keeps_the_drawer_closed(): void
    {
        $variant = $this->variant('skrab-dlya-tela');

        $this->drawer()
            ->dispatch('cart-add', variantId: $variant->id)
            ->assertSet('open', false)
            ->assertDispatched('cart-notice', message: 'Скраб для тела добавлен в корзину', success: true)
            ->assertDispatched('cart-updated', quantities: [$variant->id => 1]);

        $this->assertSame(1, $this->cart()->items->count());
    }

    public function test_out_of_stock_variant_reports_a_notice(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $variant->quota->update(['allocated' => 0]);

        $this->drawer()
            ->dispatch('cart-add', variantId: $variant->id)
            ->assertDispatched('cart-notice', message: 'Этого товара сейчас нет в наличии')
            ->assertDispatched('cart-updated', quantities: []);

        $this->assertSame(0, $this->cart()->items->count());
    }

    public function test_unknown_variant_reports_unavailability_without_adding_an_item(): void
    {
        $this->drawer()
            ->call('addToCart', ProductVariant::max('id') + 1)
            ->assertDispatched('cart-notice', message: 'Этот товар сейчас недоступен')
            ->assertDispatched('cart-updated', quantities: []);

        $this->assertSame(0, $this->cart()->items->count());
    }

    public function test_card_quantity_can_be_increased_decreased_and_removed(): void
    {
        $item = $this->addItem('skrab-dlya-tela');
        $variantId = $item->product_variant_id;

        $drawer = $this->drawer()->call('incrementVariant', $variantId)
            ->assertDispatched('cart-updated', quantities: [$variantId => 2]);
        $this->assertSame(2, $item->fresh()->qty);

        $drawer->call('decrementVariant', $variantId)
            ->assertDispatched('cart-updated', quantities: [$variantId => 1]);
        $this->assertSame(1, $item->fresh()->qty);

        $drawer->call('decrementVariant', $variantId)
            ->assertDispatched('cart-updated', quantities: []);
        $this->assertNull($item->fresh());
    }

    public function test_card_increment_reports_the_stock_limit_and_keeps_the_real_quantity(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $variant->quota->update(['allocated' => 1]);
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()->call('incrementVariant', $variant->id)
            ->assertDispatched('cart-notice', message: 'Доступное количество товара уже в корзине')
            ->assertDispatched('cart-updated', quantities: [$variant->id => 1]);

        $this->assertSame(1, $item->fresh()->qty);
    }

    public function test_card_controls_do_not_change_another_carts_item(): void
    {
        $item = $this->addItem('skrab-dlya-tela');
        $otherCart = Cart::create(['token' => Str::random(48)]);
        $this->token = $otherCart->token;

        $this->drawer()->call('incrementVariant', $item->product_variant_id)
            ->call('decrementVariant', $item->product_variant_id)
            ->assertDispatched('cart-updated', quantities: []);

        $this->assertSame(1, $item->fresh()->qty);
        $this->assertSame(0, $this->cart()->items->count());
    }

    public function test_repeated_add_at_the_stock_limit_does_not_report_success(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $variant->quota->update(['allocated' => 1]);
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()->call('addToCart', $variant->id)
            ->assertDispatched('cart-notice', message: 'Доступное количество товара уже в корзине')
            ->assertNotDispatched('cart-notice', success: true)
            ->assertDispatched('cart-updated', quantities: [$variant->id => 1]);

        $this->assertSame(1, $item->fresh()->qty);
    }

    public function test_card_controls_neither_count_nor_modify_a_gift(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $gift = PromoRule::where('type', 'gift')->firstOrFail()->gifts()->firstOrFail();
        $drawer = $this->drawer()->call('addToCart', $variant->id, 4)
            ->call('chooseGift', $gift->id)
            ->assertDispatched('cart-updated', quantities: [$variant->id => 4]);
        $giftItem = $this->cart()->giftItem();

        $drawer->call('incrementVariant', $gift->id)
            ->call('decrementVariant', $gift->id)
            ->assertDispatched('cart-updated', quantities: [$variant->id => 4]);

        $this->assertSame(1, $giftItem->fresh()->qty);
    }

    public function test_quantity_can_be_stepped_up_and_down(): void
    {
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()
            ->call('increment', $item->id)
            ->call('increment', $item->id);

        $this->assertSame(3, $item->fresh()->qty);

        $this->drawer()->call('decrement', $item->id);

        $this->assertSame(2, $item->fresh()->qty);
    }

    public function test_stepping_below_one_removes_the_item(): void
    {
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()->call('decrement', $item->id);

        $this->assertNull($item->fresh());
    }

    public function test_item_can_be_removed(): void
    {
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()
            ->call('removeItem', $item->id)
            ->assertSee('Пока пусто')
            ->assertDispatched('cart-updated', quantities: []);

        $this->assertNull($item->fresh());
    }

    public function test_another_carts_item_is_ignored(): void
    {
        $item = $this->addItem('skrab-dlya-tela');
        $otherCart = Cart::create(['token' => Str::random(48)]);

        $this->token = $otherCart->token;

        $this->drawer()->call('removeItem', $item->id);

        $this->assertNotNull($item->fresh());
    }

    public function test_free_shipping_progress_is_rendered(): void
    {
        $this->addItem('multipenka-dlya-ruk-i-stop');

        $this->drawer()
            ->assertSee('До бесплатной доставки');
    }

    public function test_gift_can_be_chosen_above_the_threshold(): void
    {
        $item = $this->addItem('skrab-dlya-tela');
        $gift = PromoRule::where('type', 'gift')->firstOrFail()->gifts()->firstOrFail();

        $this->drawer()->call('increment', $item->id)
            ->call('increment', $item->id)
            ->call('increment', $item->id);

        // 4 x 1 290 = 5 160 ₽ — порог подарка пройден.
        $this->drawer()->call('chooseGift', $gift->id);

        $this->assertNotNull($this->cart()->giftItem());
    }

    public function test_gift_below_the_threshold_is_rejected(): void
    {
        $this->addItem('multipenka-dlya-ruk-i-stop');
        $gift = PromoRule::where('type', 'gift')->firstOrFail()->gifts()->firstOrFail();

        $this->drawer()
            ->call('chooseGift', $gift->id)
            ->assertDispatched('cart-notice');

        $this->assertNull($this->cart()->giftItem());
    }

    public function test_header_counter_shows_paid_items_only(): void
    {
        $item = $this->addItem('skrab-dlya-tela');

        $this->drawer()
            ->call('increment', $item->id)
            ->assertSee('Корзина: 2');
    }

    // ───────────────────────────────────────────── helpers

    /**
     * Livewire выполняет монтирование и каждый вызов в отдельном запросе
     * и не наследует cookie из TestCase — токен корзины передаём явно.
     */
    private function drawer(): Testable
    {
        return Livewire::withCookie(CartService::COOKIE, $this->token)->test(CartDrawer::class);
    }

    private function cart(): Cart
    {
        return Cart::with('items.variant.product', 'items.variant.quota')
            ->where('token', $this->token)
            ->firstOrFail();
    }

    private function variant(string $slug): ProductVariant
    {
        return Product::where('slug', $slug)->firstOrFail()
            ->variants()->where('is_default', true)->firstOrFail();
    }

    private function addItem(string $slug)
    {
        $variant = $this->variant($slug);

        $this->drawer()->dispatch('cart-add', variantId: $variant->id);

        return $this->cart()->items->firstWhere('product_variant_id', $variant->id);
    }
}
