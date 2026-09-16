<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Личный кабинет.
 *
 * Две вещи, ради которых он существует: человек видит свои заказы и может
 * повторить любой из них. Плюс граница, которую легко проглядеть: чужие
 * заказы не должны быть видны и повторяемы.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        config([
            'livsi.yandex.client_id'     => 'test-client',
            'livsi.yandex.client_secret' => 'test-secret',
        ]);

    }

    // ─────────────────────────────────── вспомогательное

    private function customer(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'  => 'Иван',
            'email' => 'ivan+' . Str::random(6) . '@example.com',
        ], $overrides));
    }

    private function order(?User $user = null, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'number'         => 'L-' . Str::upper(Str::random(6)),
            'user_id'        => $user?->id,
            'customer_name'  => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'ivan@example.com',
            'items_total'    => 90000,
            'total'          => 90000,
            'consent_at'     => now(),
            'access_token'   => Str::random(48),
        ], $overrides));
    }

    private function item(Order $order, ProductVariant $variant, int $qty = 2, bool $gift = false): void
    {
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'sku'                => $variant->sku ?? 'SKU',
            'title'              => $variant->product->title,
            'option_label'       => $variant->storefrontLabel(),
            'unit_price'         => 45000,
            'quantity'           => $qty,
            'total'              => $gift ? 0 : 45000 * $qty,
            'is_gift'            => $gift,
        ]);
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();
    }

    /**
     * Всё, что лежит в корзинах.
     *
     * Считаем по всей таблице, а не по токену из cookie: cookie корзины
     * шифруется, и в тестах подставить её значение нельзя. База в каждом
     * тесте своя и пустая, поэтому сумма однозначна — а привязка к токену
     * молча давала бы ноль, и проверка «не добавилось» проходила бы всегда.
     */
    private function cartCount(): int
    {
        return (int) CartItem::sum('qty');
    }

    // ─────────────────────────────────── доступ

    public function test_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('account'))->assertRedirect(route('login'));
    }

    public function test_login_page_offers_the_yandex_button(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Войти через Яндекс');
    }

    public function test_header_shows_the_profile_link_when_logged_in(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Вход');

        $this->actingAs($this->customer())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Профиль');
    }

    // ─────────────────────────────────── свои заказы и только свои

    public function test_account_lists_own_orders_only(): void
    {
        $me      = $this->customer();
        $someone = $this->customer();

        $mine   = $this->order($me);
        $theirs = $this->order($someone);

        $this->actingAs($me)
            ->get(route('account'))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee($theirs->number);
    }

    /** Владельцу токен не нужен: он уже вошёл. */
    public function test_owner_opens_their_order_without_a_token(): void
    {
        $me    = $this->customer();
        $order = $this->order($me);

        $this->actingAs($me)
            ->get(route('order.show', $order->number))
            ->assertOk()
            ->assertSee($order->number);
    }

    /** Вход в аккаунт не даёт доступа к чужим заказам. */
    public function test_logged_in_stranger_cannot_open_someone_elses_order(): void
    {
        $order = $this->order($this->customer());

        $this->actingAs($this->customer())
            ->get(route('order.show', $order->number))
            ->assertNotFound();
    }

    /** Гостевой доступ по токену остаётся как был. */
    public function test_guest_still_opens_an_order_by_token(): void
    {
        $order = $this->order();

        $this->get($order->accessUrl())->assertOk();
        $this->get(route('order.show', $order->number))->assertNotFound();
    }

    // ─────────────────────────────────── повтор заказа

    public function test_repeat_puts_the_order_back_into_the_cart(): void
    {
        $me      = $this->customer();
        $order   = $this->order($me);
        $variant = $this->variant();

        $this->item($order, $variant, 2);

        $this->actingAs($me)
            ->post(route('account.repeat', $order))
            ->assertRedirect(route('cart.index'));

        $this->assertSame(2, $this->cartCount());
    }

    public function test_repeat_refuses_someone_elses_order(): void
    {
        $order = $this->order($this->customer());
        $this->item($order, $this->variant());

        $this->actingAs($this->customer())
            ->post(route('account.repeat', $order))
            ->assertNotFound();

        $this->assertSame(0, $this->cartCount());
    }

    /**
     * Подарок не повторяется.
     *
     * Его даёт промо-правило при своих условиях, а не прошлая покупка:
     * иначе повтор выдавал бы подарок на любую сумму.
     */
    public function test_repeat_does_not_copy_the_gift(): void
    {
        $me    = $this->customer();
        $order = $this->order($me);

        $variants = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->take(2)->get();

        $this->item($order, $variants[0], 1);
        $this->item($order, $variants[1], 1, gift: true);

        $this->actingAs($me)
            ->post(route('account.repeat', $order));

        $this->assertSame(1, $this->cartCount());
    }

    /** Снятое с продажи пропускается, и об этом говорится вслух. */
    public function test_repeat_reports_what_is_no_longer_available(): void
    {
        $me      = $this->customer();
        $order   = $this->order($me);
        $variant = $this->variant();

        $this->item($order, $variant, 1);

        $variant->update(['is_active' => false]);

        $this->actingAs($me)
            ->post(route('account.repeat', $order))
            ->assertRedirect();

        $this->assertSame(0, $this->cartCount());
        $this->assertStringContainsString('нет в наличии', session('status'));
    }

    // ─────────────────────────────────── свои данные

    public function test_profile_update_saves_name_and_phone(): void
    {
        $me = $this->customer();

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Пётр', 'phone' => '+79005556677'])
            ->assertRedirect();

        $me->refresh();

        $this->assertSame('Пётр', $me->name);
        $this->assertSame('+79005556677', $me->phone);
    }

    /** Телефон — будущий вход по коду: занять чужой номер нельзя. */
    public function test_phone_of_another_account_is_rejected(): void
    {
        $this->customer(['phone' => '+79005556677']);

        $me = $this->customer();

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Пётр', 'phone' => '+79005556677'])
            ->assertSessionHasErrors('phone');

        $this->assertNull($me->fresh()->phone);
    }

    /** «8 900…» и «+7 900…» — один номер: в базу кладётся один вид. */
    public function test_phone_is_normalized_before_saving(): void
    {
        $me = $this->customer();

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Иван', 'phone' => '8 (900) 111-22-33'])
            ->assertSessionHasNoErrors();

        $this->assertSame('+79001112233', $me->fresh()->phone);
    }

    /**
     * Непонятый номер — ошибка, а не тихая очистка поля.
     *
     * Раньше телефон принимался как любая строка до 20 символов: в базу
     * попадало что угодно, а вход по коду из СМС потом не нашёл бы владельца.
     */
    public function test_unparseable_phone_is_rejected_not_silently_dropped(): void
    {
        $me = $this->customer(['phone' => '+79005556677']);

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Иван', 'phone' => 'позвоните мне'])
            ->assertSessionHasErrors('phone');

        $this->assertSame('+79005556677', $me->fresh()->phone, 'Старый номер затёрли');
    }

    /** Пустое поле — это осознанная очистка, а не ошибка. */
    public function test_empty_phone_clears_the_field(): void
    {
        $me = $this->customer(['phone' => '+79005556677']);

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Иван', 'phone' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($me->fresh()->phone);
    }

    /** Занятый номер узнаётся в любом написании, иначе он достался бы двоим. */
    public function test_taken_number_is_recognised_in_another_spelling(): void
    {
        $this->customer(['phone' => '+79005556677']);

        $me = $this->customer();

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Иван', 'phone' => '8 900 555-66-77'])
            ->assertSessionHasErrors('phone');

        $this->assertNull($me->fresh()->phone);
    }

    /** Сохранение без изменений не должно спотыкаться о собственный номер. */
    public function test_saving_an_unchanged_phone_is_allowed(): void
    {
        $me = $this->customer(['phone' => '+79005556677']);

        $this->actingAs($me)
            ->patch(route('account.update'), ['name' => 'Иван', 'phone' => '+79005556677'])
            ->assertSessionHasNoErrors();
    }
}
