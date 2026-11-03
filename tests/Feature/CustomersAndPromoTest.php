<?php

namespace Tests\Feature;

use App\Mail\MarketingMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\User;
use App\Payments\ReceiptBuilder;
use App\Services\CartService;
use App\Services\MailingService;
use App\Services\OrderService;
use App\Services\PromoCodeService;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomersAndPromoTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Setting::put('vat_rate_default', '20');
        $this->token = Str::random(32);
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->where('price', '>', 50000)->firstOrFail();
    }

    private function fillCart(int $qty = 2): Cart
    {
        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('cart.add'), ['variant_id' => $this->variant()->id, 'qty' => $qty]);

        return Cart::query()->latest('id')->firstOrFail();
    }

    private function customer(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Мария', 'customer_phone' => '+7 900 111-22-33', 'customer_email' => 'Maria@Example.test',
            'delivery_method' => 'cdek_point', 'delivery_price' => 30000, 'delivery_city' => 'Ставрополь',
            'consent_at' => now(),
        ], $overrides);
    }

    private function promo(array $overrides = []): PromoCode
    {
        return PromoCode::create(array_merge(['code' => 'SALE10', 'kind' => PromoCode::PERCENT, 'value' => 10, 'active' => true,
            'once_per_customer' => true], $overrides));
    }

    // ------------------------------------------------------------ клиенты

    public function test_every_order_lands_in_customers_by_email(): void
    {
        $first = app(OrderService::class)->createFromCart($this->fillCart(), $this->customer(['marketing_consent_at' => now()->subDay()]));
        $second = app(OrderService::class)->createFromCart($this->fillCart(1), $this->customer(['customer_email' => 'maria@example.test ',
            'customer_phone' => '8 (900) 111-22-33', 'delivery_city' => 'Москва', 'customer_name' => 'Мария Петрова']));

        $this->assertSame(1, Customer::count());
        $customer = Customer::first();
        $this->assertSame([$customer->id, $customer->id], [$first->fresh()->customer_id, $second->fresh()->customer_id]);
        $this->assertSame(['maria@example.test', '79001112233', 'Москва', 'Мария Петрова'],
            [$customer->email, $customer->phone, $customer->city, $customer->name]);
        $this->assertTrue($customer->canReceiveMarketing());   // согласие из первого заказа не теряется
    }

    public function test_unsubscribed_customer_is_back_only_after_new_consent(): void
    {
        $customer = Customer::create(['email' => 'a@b.c', 'marketing_consent_at' => now()->subDays(3), 'unsubscribed_at' => now()->subDay()]);
        $this->assertFalse($customer->canReceiveMarketing());
        $this->assertSame(0, Customer::query()->subscribed()->count());

        $customer->update(['marketing_consent_at' => now()]);
        $this->assertTrue($customer->fresh()->canReceiveMarketing());
        $this->assertSame(1, Customer::query()->subscribed()->count());
    }

    public function test_registered_user_becomes_a_customer(): void
    {
        $user = User::factory()->create(['email' => 'New@User.test']);

        $this->assertSame($user->id, Customer::where('email', 'new@user.test')->value('user_id'));
    }

    // ------------------------------------------------------------ промокоды

    public function test_percent_promo_is_spread_over_goods_and_receipt_adds_up(): void
    {
        $this->promo();
        $cart = $this->fillCart(2);
        $goods = $cart->subtotal();

        $order = app(OrderService::class)->createFromCart($cart, $this->customer(), ' sale10 ');

        $discount = intdiv($goods * 10, 100);
        $this->assertSame(['SALE10', $discount, false], [$order->promo_code, (int) $order->promo_discount, (bool) $order->promo_free_delivery]);
        $this->assertSame($goods - $discount + 30000, (int) $order->total);
        $this->assertSame($discount, (int) $order->items->sum('discount'));
        $this->assertSame((int) $order->total, (new ReceiptBuilder($order->load('items')))->total());
    }

    public function test_free_delivery_promo_makes_delivery_free(): void
    {
        $this->promo(['code' => 'SHIP', 'kind' => PromoCode::FREE_DELIVERY, 'value' => 0]);
        $cart = $this->fillCart();

        $order = app(OrderService::class)->createFromCart($cart, $this->customer(), 'SHIP');

        $this->assertSame([0, true, $cart->subtotal()], [(int) $order->delivery_price, (bool) $order->promo_free_delivery, (int) $order->total]);
    }

    public function test_fixed_discount_never_takes_the_whole_order(): void
    {
        $this->promo(['code' => 'BIG', 'kind' => PromoCode::FIXED, 'value' => 99999900]);

        $result = app(PromoCodeService::class)->check('BIG', 50000, 'x@y.z', null, null);

        $this->assertSame(49900, $result['discount']);   // рубль остаётся к оплате
    }

    public function test_promo_refusals_say_why(): void
    {
        $service = app(PromoCodeService::class);
        $this->promo(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->promo(['code' => 'OFF', 'active' => false]);
        $this->promo(['code' => 'MIN', 'min_total' => 300000]);
        $owner = Customer::create(['email' => 'owner@x.ru', 'phone' => '79005556677']);
        $this->promo(['code' => 'MINE', 'customer_id' => $owner->id, 'max_uses' => 1]);

        foreach ([['NOPE', 'Такого промокода нет'], ['OLD', 'Срок действия'], ['OFF', 'больше не действует'],
            ['MIN', 'от '.Money::rub(300000)], ['MINE', 'личный промокод другого покупателя']] as [$code, $reason]) {
            try {
                $service->check($code, 100000, 'stranger@x.ru', '+7 911 000 00 00', null);
                $this->fail($code.' должен был не подойти');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($reason, $exception->validator->errors()->first('promo_code'), $code);
            }
        }
        // личный код подходит по почте владельца и по его телефону
        $this->assertSame(10000, $service->check('MINE', 100000, 'OWNER@x.ru', null, null)['discount']);
        $this->assertSame(10000, $service->check('MINE', 100000, 'other@x.ru', '8 900 555-66-77', null)['discount']);
    }

    public function test_one_time_code_is_spent_by_live_order_and_freed_by_cancelled_one(): void
    {
        $owner = Customer::create(['email' => 'maria@example.test']);
        $this->promo(['code' => 'ONCE', 'customer_id' => $owner->id, 'max_uses' => 1]);
        $order = app(OrderService::class)->createFromCart($this->fillCart(), $this->customer(), 'ONCE');

        try {
            app(PromoCodeService::class)->check('ONCE', 100000, 'maria@example.test', null, null);
            $this->fail('Одноразовый код уже потрачен заказом, который ждёт оплаты');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('уже использован', $exception->validator->errors()->first());
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->assertSame(10000, app(PromoCodeService::class)->check('ONCE', 100000, 'maria@example.test', null, null)['discount']);
    }

    public function test_public_code_once_per_customer(): void
    {
        $this->promo(['code' => 'WELCOME']);
        app(OrderService::class)->createFromCart($this->fillCart(), $this->customer(), 'WELCOME');

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createFromCart($this->fillCart(), $this->customer(), 'WELCOME');
    }

    public function test_issued_personal_code_is_one_time_and_shown_in_account(): void
    {
        $user = User::factory()->create(['email' => 'client@x.ru']);
        $customer = Customer::where('user_id', $user->id)->firstOrFail();

        $promo = app(PromoCodeService::class)->issue(['kind' => PromoCode::FIXED, 'value' => 50000, 'days' => 30, 'note' => 'за отзыв'], $customer, 'admin@livsi.shop');

        $this->assertMatchesRegularExpression('/^LIVSI-[A-Z0-9]{6}$/', $promo->code);
        $this->assertSame([1, 'admin@livsi.shop'], [$promo->max_uses, $promo->issued_by]);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Мои промокоды')->assertSee($promo->code)->assertSee('Скидка '.Money::rub(50000))->assertSee('действует');
    }

    // ------------------------------------------------------------ рассылки

    public function test_mailing_goes_only_to_consented_with_personal_codes_and_unsubscribe(): void
    {
        Mail::fake();
        $yes = Customer::create(['email' => 'yes@x.ru', 'name' => 'Анна', 'marketing_consent_at' => now()->subDay()]);
        $no = Customer::create(['email' => 'no@x.ru']);
        $gone = Customer::create(['email' => 'gone@x.ru', 'marketing_consent_at' => now()->subDays(5), 'unsubscribed_at' => now()->subDay()]);

        $mailing = app(MailingService::class)->create(['subject' => 'Скидка на пенки', 'body' => "Привет!\n\nДарим скидку на пенки.",
            'promo' => ['kind' => PromoCode::PERCENT, 'value' => 15, 'days' => 14]], [$yes, $no, $gone], 'admin');

        $this->assertSame(1, $mailing->total);
        $this->assertSame(['queued', 'skipped', 'skipped'], $mailing->recipients()->orderBy('id')->pluck('status')->all());
        $code = $yes->promoCodes()->first();
        $this->assertSame([15, 1, $mailing->id], [$code->value, $code->max_uses, $code->mailing_id]);

        $this->artisan('mailings:send')->assertSuccessful();

        Mail::assertSent(MarketingMail::class, function ($mail) use ($code) {
            $html = $mail->render();

            return $mail->hasTo('yes@x.ru') && $mail->promo?->id === $code->id
                && str_contains($html, $code->code) && str_contains($html, 'Отписаться') && str_contains($html, 'Анна, здравствуйте')
                && str_contains($mail->unsubscribeUrl, '/unsubscribe/');
        });
        Mail::assertSentCount(1);
        $mailing->refresh();
        $this->assertSame(['done', 1, 0], [$mailing->status, $mailing->sent, $mailing->failed]);

        $url = app(MailingService::class)->unsubscribeUrl($yes);
        $this->get($url)->assertOk()->assertSee('Вы отписались');
        $this->assertFalse($yes->fresh()->canReceiveMarketing());
        $this->post($url)->assertOk();   // «в один клик» из почты — без CSRF
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    public function test_mailing_to_nobody_with_consent_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        app(MailingService::class)->create(['subject' => 'Тема', 'body' => 'Достаточно длинный текст'],
            [Customer::create(['email' => 'no@x.ru'])], 'admin');
    }

    public function test_customer_who_unsubscribed_after_queueing_is_skipped(): void
    {
        Mail::fake();
        $customer = Customer::create(['email' => 'yes@x.ru', 'marketing_consent_at' => now()->subDay()]);
        $mailing = app(MailingService::class)->create(['subject' => 'Тема', 'body' => 'Достаточно длинный текст'], [$customer], 'admin');
        $customer->update(['unsubscribed_at' => now()]);

        app(MailingService::class)->sendBatch();

        Mail::assertNothingSent();
        $this->assertSame('skipped', $mailing->recipients()->value('status'));
        $this->assertSame('done', $mailing->fresh()->status);
    }

    // ------------------------------------------------------------ оформление

    public function test_checkout_applies_promo_and_shows_discount(): void
    {
        $this->promo();
        $cart = $this->fillCart(2);

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('checkout.promo'), ['promo_code' => 'sale10', 'email' => 'maria@example.test', 'name' => 'Мария'])
            ->assertRedirect(route('checkout.index'))->assertSessionHas('checkout_promo.code', 'SALE10');

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)->get(route('checkout.index'))->assertOk()
            ->assertSee('Промокод SALE10')->assertSee('Убрать')
            ->assertSee('data-items-total="'.($cart->subtotal() - intdiv($cart->subtotal() * 10, 100)).'"', false);

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)->post(route('checkout.promo.remove'))
            ->assertRedirect(route('checkout.index'))->assertSessionMissing('checkout_promo');
    }

    public function test_wrong_code_at_checkout_shows_reason(): void
    {
        $this->fillCart();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('checkout.promo'), ['promo_code' => 'NOPE', 'email' => 'm@x.ru'])
            ->assertRedirect(route('checkout.index'))->assertSessionHasErrors(['promo_code']);
    }
}
