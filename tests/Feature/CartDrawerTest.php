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

    public function test_adding_a_product_opens_the_drawer(): void
    {
        $this->drawer()
            ->dispatch('cart-add', variantId: $this->variant('skrab-dlya-tela')->id)
            ->assertSet('open', true)
            ->assertSee('Скраб для тела');

        $this->assertSame(1, $this->cart()->items->count());
    }

    public function test_out_of_stock_variant_reports_a_notice(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $variant->quota->update(['allocated' => 0]);

        $this->drawer()
            ->dispatch('cart-add', variantId: $variant->id)
            ->assertDispatched('cart-notice');

        $this->assertSame(0, $this->cart()->items->count());
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
            ->assertSee('Пока пусто');

        $this->assertNull($item->fresh());
    }

    public function test_another_carts_item_is_ignored(): void
    {
        $item      = $this->addItem('skrab-dlya-tela');
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
