<?php

namespace Tests\Feature;

use App\Auth\SocialProfile;
use App\Models\User;
use App\Services\SocialAuthService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_provider_reuses_a_customer_by_email_ignoring_case_and_spaces(): void
    {
        $customer = User::factory()->create(['email' => 'Buyer@Example.com', 'name' => 'Моё имя']);

        $signedIn = app(SocialAuthService::class)->login(new SocialProfile('vk', '123', ' BUYER@example.com ', 'Имя VK', '+79001112233'));

        $this->assertTrue($signedIn->is($customer));
        $this->assertSame('Моё имя', $signedIn->name);
        $this->assertSame('Buyer@Example.com', $signedIn->email);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $customer->id, 'provider' => 'vk', 'email' => 'buyer@example.com', 'phone' => '+79001112233']);
    }

    public function test_different_emails_and_phone_formats_reuse_the_same_customer(): void
    {
        $service = app(SocialAuthService::class);
        $yandex = $service->login(new SocialProfile('yandex', '123', 'buyer@yandex.ru', 'Покупатель', '8 (900) 111-22-33'));
        $vk = $service->login(new SocialProfile('vk', '456', 'buyer@mail.ru', 'Имя VK', '+7 900 111 22 33'));

        $this->assertTrue($vk->is($yandex));
        $this->assertSame('buyer@yandex.ru', $vk->email);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 2);
    }

    public function test_email_saved_by_another_provider_is_also_a_customer_identifier(): void
    {
        $service = app(SocialAuthService::class);
        $customer = $service->login(new SocialProfile('yandex', '123', 'first@example.com', 'Покупатель', '+79001112233'));
        $service->login(new SocialProfile('vk', '456', 'second@example.com', null, '+79001112233'));

        $signedIn = $service->login(new SocialProfile('yandex', '789', 'SECOND@example.com', null, null));

        $this->assertTrue($signedIn->is($customer));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 3);
    }

    public function test_vk_and_mail_identity_reuses_the_same_binding_even_if_contacts_change(): void
    {
        $service = app(SocialAuthService::class);
        $customer = $service->login(new SocialProfile('vk', '123', 'first@example.com', 'Покупатель', '+79001112233'));
        $customer->update(['name' => 'Правка клиента', 'phone' => '+79002223344']);

        $signedIn = $service->login(new SocialProfile('vk', '123', 'mail@example.com', 'Имя Mail', '+79003334455'));

        $this->assertTrue($signedIn->is($customer));
        $this->assertSame('Правка клиента', $signedIn->name);
        $this->assertSame('first@example.com', $signedIn->email);
        $this->assertSame('+79002223344', $signedIn->phone);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertDatabaseHas('social_accounts', ['email' => 'mail@example.com', 'phone' => '+79003334455']);
    }

    public function test_existing_identity_keeps_its_owner_when_new_contacts_match_someone_else(): void
    {
        $service = app(SocialAuthService::class);
        $owner = $service->login(new SocialProfile('yandex', '123', 'owner@example.com', null, null));
        $other = User::factory()->create(['email' => 'other@example.com']);

        $signedIn = $service->login(new SocialProfile('yandex', '123', $other->email, null, null));

        $this->assertTrue($signedIn->is($owner));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('social_accounts', ['provider_user_id' => '123', 'user_id' => $owner->id]);
    }

    public function test_known_provider_phone_can_sign_in_without_a_new_email(): void
    {
        $service = app(SocialAuthService::class);
        $customer = $service->login(new SocialProfile('yandex', '123', 'buyer@example.com', null, '+79001112233'));

        $signedIn = $service->login(new SocialProfile('vk', '456', null, null, '8 900 111 22 33'));

        $this->assertTrue($signedIn->is($customer));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 2);
    }

    public function test_old_binding_gains_a_provider_phone_after_login_and_can_link_another_provider(): void
    {
        $customer = User::factory()->create(['email' => 'buyer@example.com', 'phone' => '+79001112233']);
        $customer->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '123', 'email' => $customer->email]);
        $service = app(SocialAuthService::class);
        $service->login(new SocialProfile('yandex', '123', $customer->email, null, '+79001112233'));

        $signedIn = $service->login(new SocialProfile('vk', '456', 'different@example.com', null, '+79001112233'));

        $this->assertTrue($signedIn->is($customer));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 2);
    }

    public function test_manual_phone_edit_does_not_replace_the_provider_phone_identifier(): void
    {
        $service = app(SocialAuthService::class);
        $customer = $service->login(new SocialProfile('yandex', '123', 'buyer@example.com', null, '+79001112233'));
        $customer->update(['phone' => '+79009998877']);

        $signedIn = $service->login(new SocialProfile('vk', '456', 'different@example.com', null, '+79001112233'));

        $this->assertTrue($signedIn->is($customer));
        $this->assertSame('+79009998877', $signedIn->phone);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_manual_phone_match_cannot_link_an_account_or_create_a_duplicate(): void
    {
        $customer = User::factory()->create(['phone' => '+79001112233']);

        try {
            app(SocialAuthService::class)->login(new SocialProfile('vk', '456', 'different@example.com', null, '+79001112233'));
            $this->fail('Ручной телефон не подтверждает право на аккаунт.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Войдите прежним способом', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertSame('+79001112233', $customer->fresh()->phone);
    }

    public function test_conflicting_email_and_provider_phone_refuse_login_without_changing_accounts(): void
    {
        $service = app(SocialAuthService::class);
        $byEmail = $service->login(new SocialProfile('yandex', '123', 'email@example.com', null, null));
        $byPhone = $service->login(new SocialProfile('vk', '456', 'phone@example.com', null, '+79001112233'));

        try {
            $service->login(new SocialProfile('yandex', '789', $byEmail->email, null, $byPhone->phone));
            $this->fail('Разные владельцы контактов требуют отдельного разбора.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('разными аккаунтами', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('social_accounts', 2);
        $this->assertDatabaseMissing('social_accounts', ['provider_user_id' => '789']);
    }

    public function test_case_insensitive_email_duplicates_are_not_selected_arbitrarily(): void
    {
        User::factory()->create(['email' => 'buyer@example.com']);
        User::factory()->create(['email' => 'Buyer@example.com']);

        $this->expectException(DomainException::class);

        app(SocialAuthService::class)->login(new SocialProfile('vk', '123', 'BUYER@example.com', null, null));
    }

    public function test_administrator_with_an_existing_social_binding_is_not_allowed_customer_login(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->socialAccounts()->create(['provider' => 'vk', 'provider_user_id' => '123', 'email' => $admin->email]);

        $this->expectException(DomainException::class);

        app(SocialAuthService::class)->login(new SocialProfile('vk', '123', $admin->email, null, null));
    }

    public function test_unmatched_customer_is_created_with_normalized_contacts(): void
    {
        $signedIn = app(SocialAuthService::class)->login(new SocialProfile('yandex', '123', ' BUYER@EXAMPLE.COM ', null, '8 900 111 22 33'));

        $this->assertSame('buyer@example.com', $signedIn->email);
        $this->assertSame('+79001112233', $signedIn->phone);
        $this->assertNull($signedIn->password);
        $this->assertFalse($signedIn->is_admin);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_invalid_contacts_do_not_create_a_customer(): void
    {
        try {
            app(SocialAuthService::class)->login(new SocialProfile('vk', '123', 'not-an-email', null, '123'));
            $this->fail('Для нового клиента нужен действительный email.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('не передал адрес почты', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }
}
