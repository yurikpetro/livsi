<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Вход через Яндекс ID.
 *
 * Ответы провайдера подставляются: настоящий вход требует живого человека
 * на экране согласия, а всё, что касается нас — проверка state, создание
 * аккаунта, привязка заказа и отказ отдавать чужой — проверяется и так.
 */
class YandexAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'livsi.yandex.client_id'     => 'test-client',
            'livsi.yandex.client_secret' => 'test-secret',
        ]);
    }

    // ─────────────────────────────────── вспомогательное

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'number'         => 'L-' . Str::upper(Str::random(6)),
            'customer_name'  => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'ivan@example.com',
            'items_total'    => 100000,
            'total'          => 100000,
            'consent_at'     => now(),
            'access_token'   => Str::random(48),
        ], $overrides));
    }

    /** @param array<string, mixed> $info */
    private function fakeYandex(array $info = []): void
    {
        Http::fake([
            '*oauth.yandex.ru/token*' => Http::response(['access_token' => 'token-1']),
            '*login.yandex.ru/info*'  => Http::response(array_merge([
                'id'            => '1234567',
                'default_email' => 'buyer@yandex.ru',
                'first_name'    => 'Иван',
                'last_name'     => 'Петров',
                'default_phone' => ['id' => 1, 'number' => '+7 900 111-22-33'],
            ], $info)),
        ]);
    }

    /** Пройти путь до конца и вернуть ответ обработчика возврата. */
    private function signIn(?Order $order = null): TestResponse
    {
        $start = $this->post(route('auth.yandex'), $order ? [
            'order' => $order->number,
            'token' => $order->access_token,
        ] : []);

        return $this->get(route('auth.yandex.callback', [
            'code'  => 'auth-code',
            'state' => $this->stateFrom($start),
        ]));
    }

    private function stateFrom(TestResponse $response): string
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    // ─────────────────────────────────── начало пути

    public function test_guest_is_offered_an_account_on_the_order_page(): void
    {
        $order = $this->order();

        $this->get($order->accessUrl())
            ->assertOk()
            ->assertSee('Войти через Яндекс');
    }

    /** Без ключей кнопка вела бы в тупик. */
    public function test_offer_is_hidden_without_provider_keys(): void
    {
        config(['livsi.yandex.client_id' => null]);

        $this->get($this->order()->accessUrl())
            ->assertOk()
            ->assertDontSee('Войти через Яндекс');
    }

    public function test_redirect_goes_to_yandex_with_state_and_requested_scopes(): void
    {
        $response = $this->post(route('auth.yandex'));

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://oauth.yandex.ru/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame(route('auth.yandex.callback'), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);

        // Ровно три права: портрет и дата рождения не запрашиваются.
        $this->assertSame('login:email login:info login:default_phone', $query['scope']);
    }

    // ─────────────────────────────────── возврат

    public function test_account_is_created_and_the_order_is_attached(): void
    {
        $order = $this->order();
        $this->fakeYandex();

        $this->signIn($order)->assertRedirect($order->accessUrl());

        $user = User::where('email', 'buyer@yandex.ru')->sole();

        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame('+79001112233', $user->phone);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->password, 'У входа через провайдера пароля быть не должно');

        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_second_login_reuses_the_same_account(): void
    {
        $this->fakeYandex();

        $this->signIn();
        $this->post(route('logout'));
        $this->signIn();

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
    }

    /**
     * Подделанный state не должен впускать.
     *
     * Иначе чужой присылает ссылку со своим кодом авторизации, жертва по ней
     * проходит — и её сессия оказывается привязана к его Яндексу.
     */
    public function test_forged_state_is_rejected(): void
    {
        $this->fakeYandex();

        $this->post(route('auth.yandex'));

        $this->get(route('auth.yandex.callback', ['code' => 'auth-code', 'state' => 'подделка']))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_callback_without_a_started_flow_is_rejected(): void
    {
        $this->fakeYandex();

        $this->get(route('auth.yandex.callback', ['code' => 'auth-code', 'state' => 'что-угодно']));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /** Отказ на экране согласия — это не ошибка, а решение человека. */
    public function test_declining_consent_is_not_an_error(): void
    {
        $this->post(route('auth.yandex'));

        $this->get(route('auth.yandex.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    // ─────────────────────────────────── чужое не отдаём

    /**
     * Совпадение почты не делает человека владельцем аккаунта.
     *
     * Провайдер ручается только за свой идентификатор. Завести у него ящик
     * с чужим адресом и войти в чужой кабинет — самый дешёвый способ угона,
     * а для админской почты это отдало бы админку.
     */
    public function test_matching_email_does_not_take_over_an_existing_account(): void
    {
        $admin = User::create([
            'name'     => 'Администратор',
            'email'    => 'buyer@yandex.ru',
            'password' => 'секрет',
            'is_admin' => true,
        ]);

        $this->fakeYandex();

        $this->signIn();

        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertSame(0, SocialAccount::count());
        $this->assertTrue($admin->fresh()->is_admin);
    }

    /** Заказ привязывается только по верному токену из его же ссылки. */
    public function test_order_is_not_attached_without_a_valid_token(): void
    {
        $order = $this->order();
        $this->fakeYandex();

        $start = $this->post(route('auth.yandex'), [
            'order' => $order->number,
            'token' => Str::random(48),
        ]);

        $this->get(route('auth.yandex.callback', [
            'code'  => 'auth-code',
            'state' => $this->stateFrom($start),
        ]));

        $this->assertNull($order->fresh()->user_id);
        $this->assertAuthenticated();
    }

    /** Чужой заказ не присваивается, даже когда вход прошёл успешно. */
    public function test_someone_elses_order_is_not_reassigned(): void
    {
        $owner = User::create(['name' => 'Хозяин', 'email' => 'owner@example.com', 'password' => 'x']);
        $order = $this->order(['user_id' => $owner->id]);

        $this->fakeYandex();
        $this->signIn($order);

        $this->assertSame($owner->id, $order->fresh()->user_id);
    }

    /** Телефон уникален: занятый чужим аккаунтом просто не записывается. */
    public function test_phone_taken_by_someone_else_is_not_stolen(): void
    {
        User::create([
            'name'  => 'Кто-то',
            'email' => 'someone@example.com',
            'phone' => '+79001112233',
        ]);

        $this->fakeYandex();
        $this->signIn();

        $this->assertNull(User::where('email', 'buyer@yandex.ru')->sole()->phone);
    }

    /** Покупатель не должен попадать в Back Office. */
    public function test_account_from_the_provider_cannot_enter_the_admin_panel(): void
    {
        $this->fakeYandex();
        $this->signIn();

        $this->get('/admin')->assertForbidden();
    }

    /** Без почты чек выставить нельзя — аккаунт не создаём. */
    public function test_profile_without_an_email_is_refused(): void
    {
        $this->fakeYandex(['default_email' => null]);

        $this->signIn();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
