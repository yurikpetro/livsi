<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Services\CartService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartTest extends TestCase
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

    public function test_visiting_cart_does_not_create_a_cart_row(): void
    {
        Cart::query()->delete();

        $this->get('/cart')->assertOk()->assertSee('Пока пусто');

        $this->assertSame(0, Cart::count());
    }

    public function test_product_can_be_added_to_cart(): void
    {
        $variant = $this->variant('multipenka-dlya-ruk-i-stop');

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/add', ['variant_id' => $variant->id])
            ->assertRedirect();

        $cart = $this->cart();

        $this->assertSame(1, $cart->items->count());
        $this->assertSame(45000, $cart->subtotal());
    }

    public function test_quantity_is_capped_by_the_site_quota(): void
    {
        $variant = $this->variant('multipenka-dlya-ruk-i-stop');
        $variant->quota->update(['allocated' => 3]);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/add', ['variant_id' => $variant->id, 'qty' => 10]);

        // Квота сайта — 3 штуки, больше положить нельзя.
        $this->assertSame(3, $this->cart()->items->first()->qty);
    }

    public function test_adding_out_of_stock_variant_shows_error(): void
    {
        $variant = $this->variant('skrab-dlya-tela');
        $variant->quota->update(['allocated' => 0]);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/add', ['variant_id' => $variant->id])
            ->assertSessionHas('cart_error');

        $this->assertSame(0, $this->cart()->items->count());
    }

    public function test_quantity_can_be_changed_and_item_removed(): void
    {
        $item = $this->addItem('multipenka-dlya-ruk-i-stop', 2);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->patch('/cart/items/' . $item->id, ['qty' => 5]);

        $this->assertSame(5, $item->fresh()->qty);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->delete('/cart/items/' . $item->id);

        $this->assertNull($item->fresh());
    }

    public function test_zero_quantity_removes_the_item(): void
    {
        $item = $this->addItem('multipenka-dlya-ruk-i-stop', 2);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->patch('/cart/items/' . $item->id, ['qty' => 0]);

        $this->assertNull($item->fresh());
    }

    public function test_another_carts_item_cannot_be_touched(): void
    {
        $item      = $this->addItem('multipenka-dlya-ruk-i-stop', 1);
        $otherCart = Cart::create(['token' => Str::random(48)]);

        $this->withCookie(CartService::COOKIE, $otherCart->token)
            ->delete('/cart/items/' . $item->id)
            ->assertForbidden();

        $this->assertNotNull($item->fresh());
    }

    public function test_free_shipping_progress_is_shown(): void
    {
        // Порог 1 000 ₽, в корзине один товар за 450 ₽ — не хватает 550 ₽.
        $this->addItem('multipenka-dlya-ruk-i-stop', 1);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->get('/cart')
            ->assertOk()
            ->assertSee('До бесплатной доставки');
    }

    public function test_gift_is_locked_below_the_threshold(): void
    {
        $this->addItem('multipenka-dlya-ruk-i-stop', 1);
        $gift = $this->giftVariant();

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/gift', ['variant_id' => $gift->id])
            ->assertSessionHas('cart_error');

        $this->assertNull($this->cart()->giftItem());
    }

    public function test_gift_can_be_chosen_above_the_threshold(): void
    {
        // Порог подарка — 5 000 ₽. Скраб стоит 1 290 ₽, берём четыре штуки.
        $this->addItem('skrab-dlya-tela', 4);
        $gift = $this->giftVariant();

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/gift', ['variant_id' => $gift->id])
            ->assertSessionHas('cart_status');

        $cart = $this->cart();

        $this->assertNotNull($cart->giftItem());
        $this->assertTrue($cart->giftItem()->is_gift);
        // Подарок не участвует в сумме к оплате.
        $this->assertSame(4 * 129000, $cart->subtotal());
        $this->assertSame(0, $cart->giftItem()->lineTotal());
    }

    public function test_gift_is_dropped_when_subtotal_falls_below_the_threshold(): void
    {
        $item = $this->addItem('skrab-dlya-tela', 4);
        $gift = $this->giftVariant();

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/gift', ['variant_id' => $gift->id]);

        $this->assertNotNull($this->cart()->giftItem());

        // Убираем товары — подарок должен сняться сам, иначе его можно было бы
        // «выбить» разово и оставить в пустой корзине.
        $this->withCookie(CartService::COOKIE, $this->token)
            ->patch('/cart/items/' . $item->id, ['qty' => 1]);

        $this->assertNull($this->cart()->giftItem());
    }

    public function test_variant_outside_the_gift_list_is_rejected(): void
    {
        $this->addItem('skrab-dlya-tela', 4);
        $notAGift = $this->variant('skrab-dlya-tela');

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/gift', ['variant_id' => $notAGift->id])
            ->assertSessionHas('cart_error');

        $this->assertNull($this->cart()->giftItem());
    }

    public function test_header_shows_the_number_of_paid_items(): void
    {
        $this->addItem('multipenka-dlya-ruk-i-stop', 3);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->get('/')
            ->assertOk()
            ->assertSee('Корзина: 3');
    }

    // ───────────────────────────────────────────── helpers

    private function cart(): Cart
    {
        return Cart::with('items.variant.product', 'items.variant.quota')
            ->where('token', $this->token)
            ->firstOrFail();
    }

    private function variant(string $slug): ProductVariant
    {
        return Product::where('slug', $slug)->firstOrFail()->variants()->where('is_default', true)->firstOrFail();
    }

    private function giftVariant(): ProductVariant
    {
        return PromoRule::where('type', 'gift')->firstOrFail()->gifts()->firstOrFail();
    }

    private function addItem(string $slug, int $qty)
    {
        $variant = $this->variant($slug);

        $this->withCookie(CartService::COOKIE, $this->token)
            ->post('/cart/add', ['variant_id' => $variant->id, 'qty' => $qty]);

        return $this->cart()->items->firstWhere('product_variant_id', $variant->id);
    }
}
