<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\PromoCodesRelationManager;
use App\Filament\Resources\Mailings\Pages\CreateMailing;
use App\Filament\Resources\Mailings\Pages\ListMailings;
use App\Filament\Resources\Mailings\Pages\ViewMailing;
use App\Filament\Resources\Mailings\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Mail\MarketingMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Mailing;
use App\Models\MailingRecipient;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Клиенты, промокоды и рассылки в админке livsi.shop. */
class CustomersAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Setting::put('vat_rate_default', '20');
        $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'boss@livsi.test']);
        $this->actingAs($this->admin);
    }

    private function customerWith(string $email, bool $consent = true): Customer
    {
        return Customer::create(['email' => $email, 'name' => 'Клиент '.$email, 'phone' => '79000000000', 'city' => 'Ставрополь',
            'marketing_consent_at' => $consent ? now()->subDay() : null]);
    }

    private function order(?string $promo = null)
    {
        $token = Str::random(32);
        $variant = ProductVariant::whereHas('quota', fn ($q) => $q->where('allocated', '>', 5))->where('price', '>', 50000)->firstOrFail();
        $this->withUnencryptedCookie(CartService::COOKIE, $token)->post(route('cart.add'), ['variant_id' => $variant->id, 'qty' => 2]);

        return app(OrderService::class)->createFromCart(Cart::query()->latest('id')->firstOrFail(), [
            'customer_name' => 'Мария', 'customer_phone' => '+7 900 111-22-33', 'customer_email' => 'maria@example.test',
            'delivery_method' => 'cdek_point', 'delivery_price' => 30000, 'delivery_city' => 'Ставрополь', 'consent_at' => now(),
        ], $promo);
    }

    public function test_customers_list_and_card_show_history_and_codes(): void
    {
        PromoCode::create(['code' => 'SALE10', 'kind' => PromoCode::PERCENT, 'value' => 10, 'active' => true]);
        $order = $this->order('SALE10');
        $customer = Customer::where('email', 'maria@example.test')->firstOrFail();
        $personal = PromoCode::create(['code' => 'LIVSI-MARIA1', 'kind' => PromoCode::FREE_DELIVERY, 'value' => 0, 'active' => true,
            'customer_id' => $customer->id, 'max_uses' => 1, 'issued_by' => 'boss@livsi.test']);

        Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$customer])->assertSee('maria@example.test');
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertOk()->assertSee('Мария');
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->assertCanSeeTableRecords([$order])->assertSee('SALE10');
        Livewire::test(PromoCodesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
            ->assertCanSeeTableRecords([$personal]);
    }

    public function test_issue_personal_promo_from_customer_card_in_rubles(): void
    {
        $customer = $this->customerWith('anna@example.test');

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('issuePromo', ['kind' => PromoCode::FIXED, 'value' => '300', 'min_total' => '2000', 'days' => 14, 'note' => 'извинение'])
            ->assertHasNoActionErrors();

        $promo = PromoCode::where('customer_id', $customer->id)->sole();
        $this->assertSame([PromoCode::FIXED, 30000, 200000, 1, 'boss@livsi.test'],
            [$promo->kind, (int) $promo->value, (int) $promo->min_total, (int) $promo->max_uses, $promo->issued_by]);
        $this->assertTrue($promo->expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertStringStartsWith('LIVSI-', $promo->code);
    }

    public function test_bulk_promo_and_bulk_mailing_for_selected_customers(): void
    {
        Mail::fake();
        $yes = $this->customerWith('yes@example.test');
        $no = $this->customerWith('no@example.test', consent: false);

        Livewire::test(ListCustomers::class)
            ->selectTableRecords([$yes->getKey(), $no->getKey()])
            ->callAction(TestAction::make('issuePromoBulk')->table()->bulk(), ['kind' => PromoCode::FREE_DELIVERY, 'days' => 30])
            ->assertHasNoActionErrors();
        $this->assertSame(2, PromoCode::where('kind', PromoCode::FREE_DELIVERY)->whereNotNull('customer_id')->count());

        Livewire::test(ListCustomers::class)
            ->selectTableRecords([$yes->getKey(), $no->getKey()])
            ->callAction(TestAction::make('mailingBulk')->table()->bulk(), ['subject' => 'Новинки', 'body' => 'У нас новые скрабы, заходите посмотреть.',
                'with_promo' => true, 'promo_kind' => PromoCode::PERCENT, 'promo_value' => '15', 'promo_days' => 7])
            ->assertHasNoActionErrors();

        $mailing = Mailing::sole();
        $this->assertSame([1, 'boss@livsi.test', PromoCode::PERCENT, 15], [(int) $mailing->total, $mailing->created_by, $mailing->promo_kind, (int) $mailing->promo_value]);
        $this->assertSame(MailingRecipient::QUEUED, $mailing->recipients()->where('customer_id', $yes->id)->value('status'));
        $this->assertSame(MailingRecipient::SKIPPED, $mailing->recipients()->where('customer_id', $no->id)->value('status'));
        $this->assertSame(1, PromoCode::where('mailing_id', $mailing->id)->where('customer_id', $yes->id)->count());
    }

    public function test_mailing_with_nobody_consented_is_refused_politely(): void
    {
        $no = $this->customerWith('no@example.test', consent: false);

        Livewire::test(ListCustomers::class)
            ->selectTableRecords([$no->getKey()])
            ->callAction(TestAction::make('mailingBulk')->table()->bulk(), ['subject' => 'Новинки', 'body' => 'У нас новые скрабы, заходите посмотреть.'])
            ->assertNotified('Рассылка не создана');

        $this->assertSame(0, Mailing::count());
    }

    public function test_promo_code_created_in_rubles_and_shown_back_in_rubles(): void
    {
        Livewire::test(CreatePromoCode::class)
            ->fillForm(['code' => ' summer 300 ', 'kind' => PromoCode::FIXED, 'value' => '300', 'min_total' => '1500', 'max_uses' => 100])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = PromoCode::sole();
        $this->assertSame(['SUMMER300', 30000, 150000, 'boss@livsi.test'], [$promo->code, (int) $promo->value, (int) $promo->min_total, $promo->issued_by]);

        Livewire::test(EditPromoCode::class, ['record' => $promo->getRouteKey()])
            ->assertSchemaStateSet(['value' => '300', 'min_total' => '1500'])
            ->fillForm(['value' => '250'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(25000, (int) $promo->fresh()->value);

        Livewire::test(ListPromoCodes::class)->assertCanSeeTableRecords([$promo])->assertSee('SUMMER300');
    }

    public function test_order_page_and_list_show_used_promo(): void
    {
        PromoCode::create(['code' => 'SALE10', 'kind' => PromoCode::PERCENT, 'value' => 10, 'active' => true, 'issued_by' => 'boss@livsi.test']);
        $order = $this->order('SALE10');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertOk()->assertSee('SALE10')->assertSee('общий код');
        Livewire::test(\App\Filament\Resources\Orders\Pages\ListOrders::class, ['activeTab' => 'all'])->assertSee('промокод SALE10');
    }

    public function test_mailing_to_all_consented_test_letter_and_results_page(): void
    {
        Mail::fake();
        $yes = $this->customerWith('yes@example.test');
        $this->customerWith('no@example.test', consent: false);

        Livewire::test(ListMailings::class)
            ->callAction('testMail', ['subject' => 'Проба', 'body' => 'Проверяем, как выглядит письмо.', 'with_promo' => true,
                'promo_kind' => PromoCode::FREE_DELIVERY, 'promo_days' => 10])
            ->assertHasNoActionErrors();
        Mail::assertSent(MarketingMail::class, fn (MarketingMail $mail): bool => $mail->hasTo('boss@livsi.test'));
        $this->assertSame(0, Mailing::count());

        Livewire::test(CreateMailing::class)
            ->assertSee('Всем, кто дал согласие на рассылку и не отписался: 1')
            ->fillForm(['subject' => 'Осенняя распродажа', 'body' => 'Скидки на всё до конца недели.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $mailing = Mailing::sole();
        $this->assertSame(1, (int) $mailing->total);
        $this->assertSame([$yes->id], $mailing->recipients()->pluck('customer_id')->all());

        Livewire::test(ViewMailing::class, ['record' => $mailing->getRouteKey()])->assertOk()->assertSee('Осенняя распродажа')->assertSee('0 из 1');
        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $mailing, 'pageClass' => ViewMailing::class])
            ->assertSee('yes@example.test');
        Livewire::test(ListMailings::class)->assertCanSeeTableRecords([$mailing]);
    }

    public function test_admin_pages_open(): void
    {
        $customer = $this->customerWith('anna@example.test');
        foreach (['/admin/customers', '/admin/customers/'.$customer->id.'/edit', '/admin/promo-codes', '/admin/promo-codes/create',
            '/admin/mailings', '/admin/mailings/create'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
