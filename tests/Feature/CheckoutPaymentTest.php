<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockQuota;
use App\Payments\ReceiptBuilder;
use App\Payments\Vat;
use App\Services\CartService;
use App\Services\OrderService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Оформление заказа и оплата через ЮKassa.
 *
 * Ответы провайдера подставляются: настоящие тестовые платежи требуют
 * ключей из личного кабинета заказчика, а поведение цепочки — заказ,
 * резерв квоты, чек, вебхук, списание — проверяется и без них.
 */
class CheckoutPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        config([
            'livsi.yookassa.shop_id'    => '123456',
            'livsi.yookassa.secret_key' => 'test_secret',
        ]);

        Setting::put('vat_rate_default', '20');

        $this->token = Str::random(32);
    }

    // ─────────────────────────────────── вспомогательное

    private function fillCart(int $qty = 2): ProductVariant
    {
        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->firstOrFail();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => $qty]);

        return $variant;
    }

    private function fakeCreated(string $id = 'pay_1', string $status = Payment::STATUS_PENDING): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'id'           => $id,
                'status'       => $status,
                'paid'         => false,
                'confirmation' => ['type' => 'redirect', 'confirmation_url' => 'https://yoomoney.test/checkout/' . $id],
            ]),
        ]);
    }

    private function checkout(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->post(route('checkout.store'), array_merge([
                'name'    => 'Мария',
                'phone'   => '+7 900 000-00-00',
                'email'   => 'maria@example.test',
                'consent' => '1',
            ], $overrides));
    }

    // ─────────────────────────────────── страница оформления

    public function test_checkout_redirects_when_the_cart_is_empty(): void
    {
        $this->get(route('checkout.index'))->assertRedirect(route('cart.index'));
    }

    public function test_checkout_shows_the_order_contents(): void
    {
        $variant = $this->fillCart();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee($variant->product->title)
            ->assertSee('К оплате');
    }

    /** Выдача не должна попадать в поиск: это личная страница. */
    public function test_checkout_is_closed_from_indexing(): void
    {
        $this->fillCart();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('noindex', escape: false);
    }

    public function test_cart_links_to_checkout(): void
    {
        $this->fillCart();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee(route('checkout.index'));
    }

    // ─────────────────────────────────── создание заказа

    public function test_order_is_created_and_user_is_sent_to_payment(): void
    {
        $variant = $this->fillCart(2);
        $this->fakeCreated();

        $this->checkout()->assertRedirect('https://yoomoney.test/checkout/pay_1');

        $order = Order::sole();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertSame('Мария', $order->customer_name);
        $this->assertSame($variant->price * 2, $order->total);
        $this->assertNotNull($order->consent_at);
        $this->assertNotNull($order->access_token);
        $this->assertStringStartsWith('LV-', $order->number);
    }

    /** Позиция хранит снимок: цена и название меняются, заказ — нет. */
    public function test_order_item_keeps_a_snapshot(): void
    {
        $variant = $this->fillCart(1);
        $this->fakeCreated();
        $this->checkout();

        $item = Order::sole()->items->first();

        $variant->update(['price' => 1]);
        $variant->product->update(['title' => 'Переименован']);

        $this->assertSame($variant->sku, $item->sku);
        $this->assertNotSame('Переименован', $item->title);
        $this->assertGreaterThan(1, $item->unit_price);
        $this->assertSame(20.0, $item->vat_rate);
    }

    public function test_cart_is_emptied_after_a_successful_start(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Пока пусто');
    }

    /** Провайдер отказал — корзина остаётся, резерв снимается. */
    public function test_cart_survives_a_provider_failure(): void
    {
        $variant = $this->fillCart(2);
        $before  = $variant->quota->fresh()->reserved;

        Http::fake(['*/payments' => Http::response(['type' => 'error'], 400)]);

        $this->checkout()->assertSessionHasErrors('payment');

        $this->assertSame($before, $variant->quota->fresh()->reserved, 'Резерв не снят после отказа');

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('cart.index'))
            ->assertOk()
            ->assertSee($variant->product->title);
    }

    /**
     * Покупателю не показывают, что именно сломалось.
     *
     * Тексты исключений писались не для него: отказ провайдера ему ничего
     * не объясняет, незаданная ставка НДС и отсутствие корневых сертификатов
     * — наши недоделки. Заодно утекали внутренние адреса: при сбое TLS
     * человек видел `api.yookassa.ru` и ссылку на документацию curl.
     */
    public function test_payment_failure_is_not_explained_to_the_buyer(): void
    {
        config(['app.debug' => false]);

        $this->fillCart();

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60: SSL certificate problem: unable to get local issuer '
            . 'certificate for https://api.yookassa.ru/v3/payments',
        ));

        $this->checkout()->assertSessionHasErrors('payment');

        $message = session('errors')->first('payment');

        $this->assertStringNotContainsString('cURL', $message);
        $this->assertStringNotContainsString('yookassa', $message);
        $this->assertStringNotContainsString('certificate', $message);
    }

    /** Разработчику причина нужна на экране, иначе отладка идёт через логи. */
    public function test_payment_failure_shows_the_reason_when_debug_is_on(): void
    {
        config(['app.debug' => true]);

        $this->fillCart();

        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem'));

        $this->checkout()->assertSessionHasErrors('payment');

        $this->assertStringContainsString('cURL error 60', session('errors')->first('payment'));
    }

    /** Незаданная ставка НДС — сообщение для администратора, а не для покупателя. */
    public function test_missing_vat_rate_is_not_explained_to_the_buyer(): void
    {
        config(['app.debug' => false]);

        Setting::put('vat_rate_default', null);
        $this->fillCart();

        $this->checkout()->assertSessionHasErrors('payment');

        $this->assertStringNotContainsString('НДС', session('errors')->first('payment'));
    }

    public function test_consent_is_required(): void
    {
        $this->fillCart();

        $this->checkout(['consent' => null])->assertSessionHasErrors('consent');

        $this->assertSame(0, Order::count());
    }

    public function test_email_is_required_for_the_receipt(): void
    {
        $this->fillCart();

        $this->checkout(['email' => ''])->assertSessionHasErrors('email');
    }

    // ─────────────────────────────────── резерв квоты

    public function test_order_reserves_the_quota(): void
    {
        $variant = $this->fillCart(2);
        $quota   = $variant->quota;
        $before  = $quota->reserved;

        $this->fakeCreated();
        $this->checkout();

        $this->assertSame($before + 2, $quota->fresh()->reserved);
        $this->assertSame(0, $quota->fresh()->sold);
    }

    // ─────────────────────────────────── чек

    public function test_receipt_is_sent_with_the_payment(): void
    {
        $this->fillCart(2);
        $this->fakeCreated();
        $this->checkout();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['receipt']['items'][0]['vat_code'])
                && $body['receipt']['items'][0]['vat_code'] === 4
                && $body['receipt']['customer']['email'] === 'maria@example.test'
                && $body['capture'] === true;
        });
    }

    /** Телефон в чеке — только цифрами, иначе провайдер его отклонит. */
    public function test_receipt_normalises_the_phone(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout(['phone' => '8 (900) 111-22-33']);

        Http::assertSent(fn ($request) => $request->data()['receipt']['customer']['phone'] === '79001112233');
    }

    /** Ключ идемпотентности обязателен: без него сетевой повтор создаст второй платёж. */
    public function test_payment_is_created_with_an_idempotence_key(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        Http::assertSent(fn ($request) => filled($request->header('Idempotence-Key')[0] ?? null));
    }

    public function test_payment_is_refused_without_a_vat_rate(): void
    {
        Setting::put('vat_rate_default', null);
        $this->fillCart();

        $this->checkout()->assertSessionHasErrors('payment');

        $this->assertSame(0, Payment::count());
    }

    public function test_seller_without_vat_gets_the_no_vat_code(): void
    {
        Setting::put('vat_rate_default', null);
        Setting::put('vat_not_payer', true);

        $this->assertTrue(Vat::isConfigured());
        $this->assertSame(Vat::NONE, Vat::code(20.0));
    }

    // ─────────────────────────────────── распределение скидки

    public function test_discount_is_spread_without_losing_a_kopeck(): void
    {
        $service = app(OrderService::class);

        foreach ([[100, [333, 333, 334]], [1, [500, 500]], [999, [1000, 3000, 7]]] as [$discount, $totals]) {
            $shares = $service->spread($discount, $totals);

            $this->assertSame($discount, array_sum($shares), 'Копейки не сошлись');

            foreach ($shares as $index => $share) {
                $this->assertLessThanOrEqual($totals[$index], $share, 'Доля больше самой позиции');
            }
        }
    }

    /** Сумма позиций чека обязана совпадать с суммой платежа до копейки. */
    public function test_receipt_total_matches_the_order(): void
    {
        $variant = $this->fillCart(3);
        $this->fakeCreated();
        $this->checkout();

        $order = Order::sole();

        $this->assertSame($order->total, (new ReceiptBuilder($order))->total());
    }

    /** Строка, не делящаяся нацело после скидки, разбивается на две. */
    public function test_uneven_line_is_split_in_the_receipt(): void
    {
        $variant = ProductVariant::first();

        $order = Order::create([
            'number' => 'LV-000999', 'customer_name' => 'Тест',
            'customer_phone' => '+79000000000', 'customer_email' => 't@example.test',
            'items_total' => 1000, 'discount_total' => 1, 'total' => 999,
            'consent_at' => now(), 'access_token' => Str::random(48),
        ]);

        $order->items()->create([
            'product_variant_id' => $variant->id, 'sku' => $variant->sku,
            'title' => 'Товар', 'unit_price' => 500, 'quantity' => 2,
            'discount' => 1, 'total' => 999, 'vat_rate' => 20,
        ]);

        $items = (new ReceiptBuilder($order->load('items')))->build()['items'];

        $this->assertCount(2, $items, 'Строка не разбита');
        $this->assertSame(999, (new ReceiptBuilder($order))->total());
    }

    // ─────────────────────────────────── страница заказа

    public function test_order_page_opens_by_token(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        $order = Order::sole();

        Http::fake(['*' => Http::response(['id' => 'pay_1', 'status' => Payment::STATUS_PENDING])]);

        $this->get($order->accessUrl())->assertOk()->assertSee($order->number);
    }

    public function test_order_page_needs_the_right_token(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        $order = Order::sole();

        $this->get(route('order.show', ['order' => $order->number, 'token' => 'wrong']))->assertNotFound();
        $this->get(route('order.show', ['order' => $order->number]))->assertNotFound();
    }

    // ─────────────────────────────────── вебхук

    private function notify(string $externalId, string $status): \Illuminate\Testing\TestResponse
    {
        Http::fake(['*/payments/*' => Http::response([
            'id'             => $externalId,
            'status'         => $status,
            'payment_method' => ['type' => 'sbp'],
        ])]);

        return $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.1'])
            ->postJson(route('webhooks.yookassa'), [
                'type'   => 'notification',
                'event'  => 'payment.' . $status,
                'object' => ['id' => $externalId, 'status' => $status],
            ]);
    }

    public function test_successful_notification_marks_the_order_paid(): void
    {
        $variant = $this->fillCart(2);
        $this->fakeCreated();
        $this->checkout();

        $quota = $variant->quota->fresh();
        $this->assertSame(2, $quota->reserved);

        $this->notify('pay_1', Payment::STATUS_SUCCEEDED)->assertOk();

        $order = Order::sole();

        $this->assertTrue($order->isPaid());
        $this->assertNotNull($order->paid_at);

        // Резерв превратился в продажу, а не просто исчез.
        $quota->refresh();
        $this->assertSame(0, $quota->reserved);
        $this->assertSame(2, $quota->sold);
    }

    /** Провайдер доставляет уведомления «хотя бы один раз» — дубли будут. */
    public function test_repeated_notification_does_not_sell_twice(): void
    {
        $variant = $this->fillCart(2);
        $this->fakeCreated();
        $this->checkout();

        $this->notify('pay_1', Payment::STATUS_SUCCEEDED);
        $this->notify('pay_1', Payment::STATUS_SUCCEEDED);
        $this->notify('pay_1', Payment::STATUS_SUCCEEDED);

        $this->assertSame(2, $variant->quota->fresh()->sold, 'Списано больше одного раза');
    }

    public function test_cancelled_notification_releases_the_reserve(): void
    {
        $variant = $this->fillCart(2);
        $this->fakeCreated();
        $this->checkout();

        $this->notify('pay_1', Payment::STATUS_CANCELED)->assertOk();

        $order = Order::sole();

        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(0, $variant->quota->fresh()->reserved);
        $this->assertSame(0, $variant->quota->fresh()->sold);
    }

    /** Отмена после оплаты не должна отменять оплаченный заказ. */
    public function test_cancellation_cannot_undo_a_paid_order(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        $this->notify('pay_1', Payment::STATUS_SUCCEEDED);
        $this->notify('pay_1', Payment::STATUS_CANCELED);

        $this->assertTrue(Order::sole()->isPaid());
    }

    /** Вебхук без проверки адреса — ручка, объявляющая любой заказ оплаченным. */
    public function test_notification_from_a_foreign_address_is_rejected(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson(route('webhooks.yookassa'), ['object' => ['id' => 'pay_1', 'status' => 'succeeded']])
            ->assertForbidden();

        $this->assertFalse(Order::sole()->isPaid());
    }

    /**
     * Телу уведомления не верим: статус перепроверяется запросом к API.
     * Подделать тело просто, ответ по Basic-аутентификации — нет.
     */
    public function test_status_is_taken_from_the_api_not_from_the_body(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        // В теле «оплачено», а провайдер отвечает «ожидает».
        Http::fake(['*/payments/*' => Http::response(['id' => 'pay_1', 'status' => Payment::STATUS_PENDING])]);

        $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.1'])
            ->postJson(route('webhooks.yookassa'), [
                'object' => ['id' => 'pay_1', 'status' => Payment::STATUS_SUCCEEDED],
            ])->assertOk();

        $this->assertFalse(Order::sole()->isPaid(), 'Заказ оплачен по одному лишь телу запроса');
    }

    public function test_notification_about_an_unknown_payment_is_answered_ok(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.1'])
            ->postJson(route('webhooks.yookassa'), ['object' => ['id' => 'pay_unknown', 'status' => 'succeeded']])
            ->assertOk();
    }

    // ─────────────────────────────────── шлюз не настроен

    public function test_checkout_says_when_payments_are_not_set_up(): void
    {
        config(['livsi.yookassa.shop_id' => null, 'livsi.yookassa.secret_key' => null]);

        $this->fillCart();

        $this->withUnencryptedCookie(CartService::COOKIE, $this->token)
            ->get(route('checkout.index'))
            ->assertOk()
            ->assertSee('Приём оплаты ещё не настроен');
    }

    /**
     * Список сетей задаётся окружением.
     *
     * Локально уведомление приходит через туннель, у которого адрес свой,
     * и без возможности расширить список вебхук не проверить вообще.
     */
    public function test_webhook_allowlist_is_configurable(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        // Чужой адрес отклоняется списком по умолчанию.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson(route('webhooks.yookassa'), ['object' => ['id' => 'pay_1']])
            ->assertForbidden();

        config(['livsi.yookassa.webhook_ips' => ['203.0.113.0/24']]);

        Http::fake(['*/payments/*' => Http::response(['id' => 'pay_1', 'status' => Payment::STATUS_SUCCEEDED])]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson(route('webhooks.yookassa'), ['object' => ['id' => 'pay_1']])
            ->assertOk();

        $this->assertTrue(Order::sole()->isPaid());
    }

    /**
     * Подделать оплату нельзя даже без проверки адреса.
     *
     * Это второй рубеж: статус всё равно берётся из API, поэтому пустой
     * список сетей — вопрос удобства локальной проверки, а не дыра.
     */
    public function test_forged_notification_cannot_mark_an_order_paid(): void
    {
        $this->fillCart();
        $this->fakeCreated();
        $this->checkout();

        config(['livsi.yookassa.webhook_ips' => []]);

        Http::fake(['*/payments/*' => Http::response(['id' => 'pay_1', 'status' => Payment::STATUS_PENDING])]);

        $this->postJson(route('webhooks.yookassa'), [
            'object' => ['id' => 'pay_1', 'status' => Payment::STATUS_SUCCEEDED],
        ])->assertOk();

        $this->assertFalse(Order::sole()->isPaid(), 'Заказ оплачен по поддельному уведомлению');
    }

    // ─────────────────────────────────── ставки НДС

    /**
     * Ставка задаётся свободно, а не выбором из списка.
     *
     * Ставки меняет закон: список из 0/10/20 устарел, как только НДС
     * подняли до 22 %, и менять его выкатом — неправильно.
     */
    public function test_any_rate_can_be_used_when_its_code_is_known(): void
    {
        // Ставки, которой сегодня нет в законе: проверяем, что заказчику
        // хватит настроек, а не выката, если она завтра появится.
        Setting::put('vat_rate_default', '25');
        Setting::put('vat_codes', Vat::DEFAULT_CODES + ['25' => 13]);

        $this->assertTrue(Vat::isConfigured());
        $this->assertSame(13, Vat::code(25.0));

        $this->fillCart();
        $this->fakeCreated();
        $this->checkout()->assertRedirect();

        Http::assertSent(fn ($request) => $request->data()['receipt']['items'][0]['vat_code'] === 13);
    }

    /**
     * Коды ставок по умолчанию — из документации ЮKassa.
     *
     * Порядок кодов не повторяет порядок ставок: 5 % — это код 7,
     * а 7 % — код 8. Перепутать их легко, а обнаружится это только
     * при сверке с налоговой, поэтому таблица закреплена тестом.
     */
    public function test_default_codes_match_the_provider_documentation(): void
    {
        $this->assertSame(
            ['22' => 11, '20' => 4, '10' => 3, '7' => 8, '5' => 7, '0' => 2],
            Vat::codes(),
        );
    }

    /**
     * Код ставки не угадывается.
     *
     * В чек уходит код, а не процент, и назначает его ФНС. Подставить
     * наугад — значит выпустить неверный чек, поэтому платёж не создаётся
     * и сообщение прямо называет ставку.
     */
    public function test_rate_without_a_code_refuses_the_payment(): void
    {
        Setting::put('vat_rate_default', '25');
        Setting::put('vat_codes', Vat::DEFAULT_CODES);

        $this->assertFalse(Vat::isConfigured());

        $this->fillCart();

        $this->checkout()->assertSessionHasErrors('payment');

        $this->assertSame(0, Payment::count());
    }

    public function test_missing_code_message_names_the_rate(): void
    {
        Setting::put('vat_codes', Vat::DEFAULT_CODES);

        try {
            Vat::code(25.0);
            $this->fail('Ожидалась ошибка про отсутствующий код');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('25', $e->getMessage());
        }
    }

    /** «20.00» и «20» — одна ставка, а не две разные. */
    public function test_rate_is_matched_regardless_of_trailing_zeros(): void
    {
        Setting::put('vat_codes', ['20.00' => 4]);

        $this->assertSame(4, Vat::code(20.0));
        $this->assertSame(4, Vat::code(20.00));
    }

    /** Пустая таблица кодов не должна ронять чек — берутся значения по умолчанию. */
    public function test_empty_code_table_falls_back_to_defaults(): void
    {
        Setting::put('vat_codes', []);

        $this->assertSame(4, Vat::code(20.0));
    }

    /**
     * Ставка без кода не должна превращаться в нулевой код.
     *
     * Заказчик заводит строку новой ставки раньше, чем узнаёт её код, —
     * и оставляет поле пустым. Если не отфильтровать такую строку,
     * приведение к целому даст ноль, а ноль — это код ставки 0 %:
     * чек уйдёт без налога и будет недействителен.
     */
    public function test_rate_with_a_blank_code_is_not_turned_into_zero(): void
    {
        Setting::put('vat_codes', Vat::DEFAULT_CODES + ['25' => '']);

        $this->assertArrayNotHasKey('25', Vat::codes(), 'Ставка без кода попала в рабочий список');

        $this->expectException(\RuntimeException::class);

        Vat::code(25.0);
    }

    /** Известные ставки из умолчаний работают без настройки. */
    public function test_known_rates_work_out_of_the_box(): void
    {
        Setting::query()->where('key', 'vat_codes')->delete();

        $this->assertSame(11, Vat::code(22.0));
        $this->assertSame(3, Vat::code(10.0));
        $this->assertSame(2, Vat::code(0.0));
        $this->assertSame(7, Vat::code(5.0));
        $this->assertSame(8, Vat::code(7.0));
    }
}
