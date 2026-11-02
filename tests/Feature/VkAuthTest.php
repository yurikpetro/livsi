<?php

namespace Tests\Feature;

use App\Auth\VkId;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VkAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'livsi.vk.client_id' => '54822915',
            'livsi.vk.redirect_uri' => null,
            'livsi.yandex.client_id' => 'yandex-client',
            'livsi.yandex.client_secret' => 'yandex-secret',
        ]);
    }

    public function test_login_and_order_pages_offer_all_three_methods(): void
    {
        $this->get(route('login'))
            ->assertSee('Войти через Яндекс')
            ->assertSee('Войти через ВКонтакте')
            ->assertSee('Войти через Mail.ru');

        config(['livsi.yandex.client_id' => null]);

        $this->get($this->order()->accessUrl())
            ->assertSee('Войти через ВКонтакте')
            ->assertSee('Войти через Mail.ru')
            ->assertDontSee('Войти через Яндекс');
    }

    public function test_unconfigured_methods_are_hidden_and_cannot_start_a_flow(): void
    {
        config(['livsi.vk.client_id' => null, 'livsi.yandex.client_id' => null]);

        $this->get(route('login'))
            ->assertDontSee('Войти через ВКонтакте')
            ->assertDontSee('Войти через Mail.ru')
            ->assertSee('Вход временно недоступен');

        $this->from(route('login'))->post(route('auth.mail'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('auth.vk.flow');
    }

    public function test_www_login_moves_to_callback_domain_before_starting_session_flow(): void
    {
        config(['livsi.vk.redirect_uri' => 'https://livsi.shop/auth/vk/callback']);

        $this->get('https://www.livsi.shop/login')->assertRedirect('https://livsi.shop/login')
            ->assertSessionMissing('auth.vk.flow');
        $this->get('https://livsi.shop/login')->assertSee('Войти через ВКонтакте')->assertSee('Войти через Mail.ru');
    }

    public function test_www_guest_order_keeps_access_token_when_moving_to_callback_domain(): void
    {
        config(['livsi.vk.redirect_uri' => 'https://livsi.shop/auth/vk/callback']);
        $order = $this->order();
        $path = '/order/'.$order->number.'?token='.$order->access_token;

        $this->get('https://www.livsi.shop'.$path)->assertRedirect('https://livsi.shop'.$path);
        $this->get('https://livsi.shop'.$path)->assertSee('Войти через Mail.ru');

        $this->assertNull($order->fresh()->user_id);
    }

    /** @return array<string, array{string, string}> */
    public static function providers(): array
    {
        return ['VK' => ['vk', 'vkid'], 'Mail' => ['mail', 'mail_ru']];
    }

    #[DataProvider('providers')]
    public function test_redirect_selects_provider_and_keeps_pkce_secret_in_session(string $provider, string $remoteProvider): void
    {
        config(['livsi.vk.redirect_uri' => 'https://livsi.shop/auth/vk/callback']);

        $response = $this->post(route('auth.'.$provider));
        $query = $this->authQuery($response);
        $verifier = session('auth.vk.flow.verifier');

        $this->assertSame('https://id.vk.ru/authorize', strtok($response->headers->get('Location'), '?'));
        $this->assertSame($remoteProvider, $query['provider']);
        $this->assertSame('54822915', $query['client_id']);
        $this->assertSame('https://livsi.shop/auth/vk/callback', $query['redirect_uri']);
        $this->assertSame('email phone', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('auth.vk.flow.state'), $query['state']);
        $this->assertSame(64, strlen($verifier));
        $this->assertStringNotContainsString($verifier, $response->headers->get('Location'));
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    public function test_pkce_challenge_matches_rfc_7636_example(): void
    {
        $url = app(VkId::class)->authUrl('state', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'vk');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $query['code_challenge']);
    }

    #[DataProvider('providers')]
    public function test_login_creates_customer_and_exchanges_code_on_server(string $provider, string $remoteProvider): void
    {
        $this->fakeVk();
        $query = $this->authQuery($this->post(route('auth.'.$provider)));
        $verifier = session('auth.vk.flow.verifier');

        $this->completeCallback($query['state'])->assertRedirect(route('account'))
            ->assertSessionMissing('auth.vk.flow');

        $user = User::where('email', 'buyer@mail.ru')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame('+79001112233', $user->phone);
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->password);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'vk', 'provider_user_id' => '1234567', 'user_id' => $user->id]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://id.vk.ru/oauth2/auth'
            && $request->method() === 'POST' && $request['code_verifier'] === $verifier
            && $request['code'] === 'auth-code' && $request['device_id'] === 'device-1'
            && $request['redirect_uri'] === route('auth.vk.callback') && ! isset($request['client_secret']));
        Http::assertSentCount(2);
        $this->get('/admin')->assertForbidden();
    }

    public function test_mail_and_vk_reuse_the_same_vk_id_identity_without_email_on_repeat(): void
    {
        $user = User::factory()->create(['email' => 'buyer@mail.ru', 'name' => 'Своё имя']);
        $user->socialAccounts()->create(['provider' => 'vk', 'provider_user_id' => '1234567']);
        $this->fakeVk(['email' => null]);

        $this->signIn('mail')->assertRedirect(route('account'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Своё имя', $user->fresh()->name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    #[DataProvider('providers')]
    public function test_new_vk_or_mail_login_uses_the_yandex_customer_and_preserves_orders(string $provider, string $remoteProvider): void
    {
        $customer = User::factory()->create(['email' => 'buyer@mail.ru']);
        $customer->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => 'yandex-123', 'email' => $customer->email]);
        $oldOrder = $this->order(['user_id' => $customer->id]);
        $guestOrder = $this->order();
        $this->fakeVk();

        $this->signIn($provider, ['order' => $guestOrder->number, 'token' => $guestOrder->access_token])
            ->assertRedirect($guestOrder->accessUrl());

        $this->assertAuthenticatedAs($customer);
        $this->get(route('account'))->assertOk()->assertSee($oldOrder->number)->assertSee($guestOrder->number);
        $this->assertSame($customer->id, $guestOrder->fresh()->user_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 2);
        Http::assertSentCount(2);
    }

    public function test_conflicting_contacts_explain_the_problem_without_signing_in(): void
    {
        User::factory()->create(['email' => 'buyer@mail.ru']);
        $byPhone = User::factory()->create(['email' => 'phone@example.com']);
        $byPhone->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '123', 'phone' => '+79001112233']);
        $this->fakeVk();

        $this->signIn()->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Почта и телефон связаны с разными аккаунтами. Напишите нам — поможем объединить их.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('social_accounts', 1);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStates(): array
    {
        return ['forged' => ['forged'], 'missing' => [null], 'array' => [['state']]];
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_state_is_rejected_before_contacting_provider(mixed $state): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->post(route('auth.vk'));

        $this->completeCallback($state)->assertRedirect(route('login'))->assertSessionMissing('auth.vk.flow');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_expired_flow_and_callback_without_flow_are_rejected(): void
    {
        Http::fake();
        $state = $this->authQuery($this->post(route('auth.vk')))['state'];
        $this->travel(11)->minutes();

        $this->completeCallback($state)->assertRedirect(route('login'));
        $this->completeCallback($state)->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_declined_consent_does_not_create_a_customer(): void
    {
        Http::fake();
        $state = $this->authQuery($this->post(route('auth.mail')))['state'];

        $this->get(route('auth.vk.callback', ['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Вход отменён.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function incompleteCallbacks(): array
    {
        return ['code missing' => [['code' => null]], 'device missing' => [['device_id' => null]], 'code array' => [['code' => ['x']]], 'device array' => [['device_id' => ['x']]]];
    }

    #[DataProvider('incompleteCallbacks')]
    public function test_incomplete_callback_never_contacts_provider(array $overrides): void
    {
        Http::fake();
        $state = $this->authQuery($this->post(route('auth.vk')))['state'];

        $this->completeCallback($state, $overrides)->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_cannot_be_replayed(): void
    {
        $this->fakeVk(['email' => null]);
        $state = $this->authQuery($this->post(route('auth.vk')))['state'];

        $this->completeCallback($state);
        $this->completeCallback($state)->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_email_is_explained_without_creating_an_account(): void
    {
        $this->fakeVk(['email' => null]);

        $this->signIn()->assertSessionHas('status', 'VK ID не передал почту. Разрешите передачу почты или выберите другой способ входа.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertSentCount(2);
    }

    public function test_email_from_token_exchange_is_used_when_user_info_omits_it(): void
    {
        $this->fakeVk(['email' => null], ['email' => 'buyer@mail.ru']);

        $this->signIn()->assertRedirect(route('account'));

        $this->assertAuthenticatedAs(User::where('email', 'buyer@mail.ru')->sole());
        Http::assertSentCount(2);
    }

    public function test_public_info_can_supply_email_for_the_same_identity(): void
    {
        $this->fakeVk(['email' => null], ['id_token' => 'provider-id-token']);
        Http::fake(['https://id.vk.ru/oauth2/public_info' => Http::response([
            'user' => ['user_id' => '1234567', 'email' => 'buyer@mail.ru'],
        ])]);

        $this->signIn()->assertRedirect(route('account'));

        $this->assertAuthenticatedAs(User::where('email', 'buyer@mail.ru')->sole());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://id.vk.ru/oauth2/public_info'
            && $request['id_token'] === 'provider-id-token' && $request['client_id'] === '54822915');
        Http::assertSentCount(3);
    }

    public function test_public_info_email_for_another_identity_is_not_accepted(): void
    {
        $this->fakeVk(['email' => null], ['id_token' => 'provider-id-token']);
        Http::fake(['https://id.vk.ru/oauth2/public_info' => Http::response([
            'user' => ['user_id' => '999', 'email' => 'someone@example.com'],
        ])]);

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(3);
    }

    public function test_failed_public_info_keeps_missing_email_message_and_does_not_create_account(): void
    {
        $this->fakeVk(['email' => null], ['id_token' => 'provider-id-token']);
        Http::fake(['https://id.vk.ru/oauth2/public_info' => Http::failedConnection()]);

        $this->signIn()->assertSessionHas('status', 'VK ID не передал почту. Разрешите передачу почты или выберите другой способ входа.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_callback_cannot_supply_its_own_email_when_provider_omits_it(): void
    {
        $this->fakeVk(['email' => null]);
        $state = $this->authQuery($this->post(route('auth.vk')))['state'];

        $this->completeCallback($state, ['email' => 'someone@example.com'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(2);
    }

    public function test_missing_email_diagnostics_do_not_log_contacts_tokens_or_unknown_scope_values(): void
    {
        $this->fakeVk(['email' => 'private-profile-value'], [
            'email' => 'private-token-value',
            'scope' => 'email phone private-scope-value',
        ]);
        Log::spy();

        $this->signIn()->assertRedirect(route('login'));

        Log::shouldHaveReceived('warning')->with('VK ID: почта не получена.', [
            'granted_scopes' => ['email', 'phone'],
            'profile_email_present' => true,
            'token_email_present' => true,
            'id_token_present' => false,
            'public_info_status' => null,
        ])->once();
        Http::assertSentCount(2);
    }

    public function test_matching_email_does_not_take_over_an_existing_admin(): void
    {
        $admin = User::factory()->create(['email' => 'buyer@mail.ru', 'is_admin' => true]);
        $this->fakeVk();

        $this->signIn()->assertSessionHas('status', 'Этот адрес почты уже занят другим аккаунтом. Напишите нам — поможем войти.');

        $this->assertGuest();
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidTokens(): array
    {
        return ['API error' => [['error' => 'invalid_grant']], 'state mismatch' => [['state' => 'other']], 'empty token' => [['access_token' => '']], 'missing identity' => [['user_id' => null]]];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_response_prevents_profile_fetch_and_login(array $overrides): void
    {
        $this->fakeVk([], $overrides);

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
    }

    public function test_profile_for_a_different_identity_is_rejected(): void
    {
        $this->fakeVk(['user_id' => '999']);

        $this->signIn()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(2);
    }

    public function test_network_failure_does_not_expose_credentials_or_create_an_account(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://id.vk.ru/oauth2/auth' => Http::failedConnection()]);

        $this->signIn()->assertSessionHas('status', 'Войти не получилось. Попробуйте позже или выберите другой способ входа.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_guest_order_is_attached_after_mail_login(): void
    {
        $order = $this->order();
        $this->fakeVk();

        $this->signIn('mail', ['order' => $order->number, 'token' => $order->access_token])
            ->assertRedirect($order->accessUrl());

        $this->assertSame(auth()->id(), $order->fresh()->user_id);
        Http::assertSentCount(2);
    }

    public function test_wrong_order_token_does_not_attach_order(): void
    {
        $order = $this->order();
        $this->fakeVk();

        $this->signIn('vk', ['order' => $order->number, 'token' => 'wrong'])->assertRedirect(route('account'));

        $this->assertNull($order->fresh()->user_id);
        Http::assertSentCount(2);
    }

    public function test_owned_order_is_not_reassigned_to_new_customer(): void
    {
        $owner = User::factory()->create();
        $order = $this->order(['user_id' => $owner->id]);
        $this->fakeVk();

        $this->signIn('vk', ['order' => $order->number, 'token' => $order->access_token]);

        $this->assertSame($owner->id, $order->fresh()->user_id);
        $this->assertNotSame($owner->id, auth()->id());
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $overrides */
    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'number' => 'L-'.Str::upper(Str::random(6)),
            'customer_name' => 'Иван',
            'customer_phone' => '+79001112233',
            'customer_email' => 'ivan@example.com',
            'items_total' => 100000,
            'total' => 100000,
            'consent_at' => now(),
            'access_token' => Str::random(48),
        ], $overrides));
    }

    /** @param array<string, mixed> $userOverrides
     * @param  array<string, mixed>  $tokenOverrides
     */
    private function fakeVk(array $userOverrides = [], array $tokenOverrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://id.vk.ru/oauth2/auth' => fn (Request $request) => Http::response(array_merge([
                'access_token' => 'token-1', 'user_id' => 1234567, 'state' => $request['state'],
            ], $tokenOverrides)),
            'https://id.vk.ru/oauth2/user_info' => Http::response(['user' => array_merge([
                'user_id' => '1234567', 'email' => 'buyer@mail.ru', 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+7 900 111-22-33',
            ], $userOverrides)]),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function signIn(string $provider = 'vk', array $data = []): TestResponse
    {
        $state = $this->authQuery($this->post(route('auth.'.$provider), $data))['state'];

        return $this->completeCallback($state);
    }

    /** @param array<string, mixed> $overrides */
    private function completeCallback(mixed $state, array $overrides = []): TestResponse
    {
        return $this->get(route('auth.vk.callback', array_merge([
            'state' => $state, 'code' => 'auth-code', 'device_id' => 'device-1',
        ], $overrides)));
    }

    /** @return array<string, string> */
    private function authQuery(TestResponse $response): array
    {
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }
}
