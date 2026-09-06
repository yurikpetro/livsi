<?php

namespace Tests\Feature;

use App\Models\LeadRequest;
use App\Models\LegalPage;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\CookieConsent;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Юридический блок: документы по своим адресам, раздельные согласия,
 * баннер про cookie и реквизиты продавца.
 *
 * В прототипе все документы были якорями на главную
 * (docs/07-design-review.md § 4.1). Постоянные адреса нужны потому, что
 * на них ссылаются чекбоксы согласий, кассовый чек, письма и уведомление
 * в Роскомнадзор — якорь в такой ссылке недопустим.
 */
class LegalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(LegalPagesSeeder::class);
    }

    // ─────────────────────────────────── документы

    public static function documents(): array
    {
        return [
            'оферта'   => ['offer', 'Публичная оферта'],
            'политика' => ['privacy', 'Политика конфиденциальности'],
            'согласие' => ['consent', 'Согласие на обработку персональных данных'],
            'доставка' => ['delivery', 'Доставка и оплата'],
            'возврат'  => ['returns', 'Возврат товара'],
        ];
    }

    #[DataProvider('documents')]
    public function test_document_opens_at_its_own_address(string $slug, string $title): void
    {
        $this->get('/' . $slug)
            ->assertOk()
            ->assertSee($title)
            ->assertSee('Редакция от');
    }

    public function test_every_declared_document_exists(): void
    {
        foreach (array_keys(LegalPage::SLUGS) as $slug) {
            $this->assertDatabaseHas('legal_pages', ['slug' => $slug]);
        }
    }

    public function test_disabled_document_returns_404(): void
    {
        LegalPage::where('slug', 'offer')->update(['is_active' => false]);

        $this->get('/offer')->assertNotFound();
    }

    /** Оферта обязана описывать отказ от товара при дистанционной продаже. */
    public function test_offer_covers_the_distance_selling_withdrawal(): void
    {
        $html = $this->get('/offer')->assertOk()->getContent();

        $this->assertStringContainsString('26.1', $html);
        $this->assertStringContainsString('семи дней', $html);
        $this->assertStringContainsString('трёх месяцев', $html);
    }

    /**
     * Страница возврата должна повторять те же условия.
     *
     * Ранее я писал, что косметика надлежащего качества возврату не подлежит.
     * Для дистанционной торговли это неверно, заказчик поправил, и ошибка
     * не должна вернуться через текст документа (`06-scope-v2.md` § 2.2).
     */
    public function test_returns_page_states_the_corrected_rules(): void
    {
        $html = $this->get('/returns')->assertOk()->getContent();

        foreach ([
            'в течение семи дней',
            'три месяца',
            'бывшей в употреблении',
            'несёт покупатель',
            'десяти дней',
            'быстрых платежей',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $html, "В тексте нет: {$fragment}");
        }
    }

    public function test_document_links_to_the_others(): void
    {
        $html = $this->get('/offer')->assertOk()->getContent();

        foreach (['privacy', 'consent', 'delivery', 'returns'] as $slug) {
            $this->assertStringContainsString(route('legal.' . $slug), $html);
        }
    }

    // ─────────────────────────────────── реквизиты

    public function test_document_shows_seller_details(): void
    {
        SellerProfile::query()->update(['is_default' => false]);

        SellerProfile::create([
            'code'       => 'test',
            'legal_name' => 'ИП Тестов Тест Тестович',
            'inn'        => '260000000000',
            'ogrn'       => '300000000000000',
            'address'    => 'Ставрополь, ул. Тестовая, 1',
            'is_default' => true,
        ]);

        $this->get('/offer')
            ->assertOk()
            ->assertSee('ИП Тестов Тест Тестович')
            ->assertSee('260000000000')
            ->assertSee('Ставрополь, ул. Тестовая, 1');
    }

    /** Без профиля — честное состояние, а не выдуманный ИНН. */
    public function test_document_without_seller_says_so(): void
    {
        SellerProfile::query()->delete();

        $this->get('/offer')
            ->assertOk()
            ->assertSee('Реквизиты будут указаны здесь до начала приёма заказов');
    }

    public function test_footer_links_to_every_document_on_any_page(): void
    {
        foreach (['/', '/catalog', '/partners'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (array_keys(LegalPage::SLUGS) as $slug) {
                $this->assertStringContainsString(route('legal.' . $slug), $html, "На {$url} нет ссылки на /{$slug}");
            }

            $this->assertStringContainsString(route('contacts'), $html);
        }
    }

    public function test_footer_shows_seller_details(): void
    {
        SellerProfile::query()->update(['is_default' => false]);
        SellerProfile::create(['code' => 'f', 'legal_name' => 'ИП Подвалов', 'inn' => '111111111111', 'is_default' => true]);

        $this->get('/')->assertOk()->assertSee('ИП Подвалов')->assertSee('ИНН 111111111111');
    }

    // ─────────────────────────────────── контакты

    public function test_contacts_page_opens(): void
    {
        $this->get('/contacts')->assertOk()->assertSee('Контакты');
    }

    public function test_contacts_page_shows_what_is_filled(): void
    {
        SellerProfile::query()->update(['is_default' => false]);
        SellerProfile::create([
            'code' => 'c', 'legal_name' => 'ИП Контактов',
            'email' => 'hello@livsi.shop', 'phone' => '+7 900 000-00-00', 'is_default' => true,
        ]);

        $this->get('/contacts')
            ->assertOk()
            ->assertSee('hello@livsi.shop')
            ->assertSee('+7 900 000-00-00');
    }

    public function test_contacts_page_without_data_says_so(): void
    {
        SellerProfile::query()->delete();
        \App\Models\Setting::put('manager_email', '');
        \App\Models\Setting::put('telegram_url', '');
        \App\Models\Setting::put('whatsapp_url', '');

        $this->get('/contacts')
            ->assertOk()
            ->assertSee('Контакты появятся здесь после заполнения настроек сайта');
    }

    // ─────────────────────────────────── баннер cookie

    public function test_cookie_bar_is_on_the_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-cookie-bar', $html);
        $this->assertStringContainsString('data-cookie-choice="necessary"', $html);
        $this->assertStringContainsString('data-cookie-choice="all"', $html);
        $this->assertStringContainsString(route('legal.privacy'), $html);
    }

    /**
     * «Только необходимые» стоит первой.
     *
     * Порядок кнопок — это и есть подталкивание: выбор по умолчанию
     * не должен склонять к согласию на слежение.
     */
    public function test_declining_button_comes_first(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'data-cookie-choice="all"'),
            strpos($html, 'data-cookie-choice="necessary"'),
            'Кнопка «Принять все» оказалась первой',
        );
    }

    /** Баннер скрыт разметкой: показывает его скрипт, если выбора ещё нет. */
    public function test_cookie_bar_starts_hidden(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $position = strpos($html, 'data-cookie-bar');
        $this->assertNotFalse($position, 'Баннера нет в разметке');

        $this->assertStringContainsString('hidden', substr($html, $position, 300));
    }

    public function test_analytics_allowed_only_after_full_consent(): void
    {
        $request = fn (?string $value) => Request::create('/', 'GET', [], $value ? [CookieConsent::COOKIE => $value] : []);

        $this->assertTrue(CookieConsent::analyticsAllowed($request(CookieConsent::ALL)));
        $this->assertFalse(CookieConsent::analyticsAllowed($request(CookieConsent::NECESSARY)));
        $this->assertFalse(CookieConsent::analyticsAllowed($request(null)));

        $this->assertTrue(CookieConsent::decided($request(CookieConsent::NECESSARY)));
        $this->assertFalse(CookieConsent::decided($request(null)));
    }

    // ─────────────────────────────────── раздельные согласия

    public static function forms(): array
    {
        return [
            'контрактное производство' => ['/contract-manufacturing', 'contract.store'],
            'оптовые партнёры'         => ['/partners', 'partners.store'],
        ];
    }

    #[DataProvider('forms')]
    public function test_form_has_two_separate_consents(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('name="consent"', $html);
        $this->assertStringContainsString('name="marketing_consent"', $html);

        // Оба согласия ссылаются на документы, а не просто называют их.
        $this->assertStringContainsString(route('legal.consent'), $html);
        $this->assertStringContainsString(route('legal.privacy'), $html);
    }

    #[DataProvider('forms')]
    public function test_neither_consent_is_pre_checked(string $url): void
    {
        $html = $this->get($url)->assertOk()->getContent();

        foreach (['name="consent"', 'name="marketing_consent"'] as $field) {
            $position = strpos($html, $field);
            $chunk    = substr($html, $position, 120);

            $this->assertStringNotContainsString('checked', $chunk, "Согласие {$field} предзаполнено");
        }
    }

    #[DataProvider('forms')]
    public function test_lead_is_accepted_without_marketing_consent(string $url, string $route): void
    {
        Mail::fake();

        $this->post(route($route), ['name' => 'Иван', 'contact' => '@ivan', 'consent' => '1'])
            ->assertRedirect();

        $lead = LeadRequest::sole();

        $this->assertNotNull($lead->consent_at);
        $this->assertNull($lead->marketing_consent_at, 'Рассылка отмечена без согласия');
    }

    #[DataProvider('forms')]
    public function test_marketing_consent_is_recorded_separately(string $url, string $route): void
    {
        Mail::fake();

        $this->post(route($route), [
            'name' => 'Иван', 'contact' => '@ivan',
            'consent' => '1', 'marketing_consent' => '1',
        ])->assertRedirect();

        $lead = LeadRequest::sole();

        $this->assertNotNull($lead->marketing_consent_at);
        $this->assertNotNull($lead->consent_at);
    }

    /** Заявку без согласия на обработку данных принимать нельзя. */
    #[DataProvider('forms')]
    public function test_marketing_consent_alone_is_not_enough(string $url, string $route): void
    {
        $this->post(route($route), [
            'name' => 'Иван', 'contact' => '@ivan', 'marketing_consent' => '1',
        ])->assertSessionHasErrors('consent');

        $this->assertSame(0, LeadRequest::count());
    }

    // ─────────────────────────────────── карта сайта и админка

    public function test_documents_are_in_the_sitemap(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        foreach (array_keys(LegalPage::SLUGS) as $slug) {
            $response->assertSee(route('legal.' . $slug));
        }

        $response->assertSee(route('contacts'));
    }

    public function test_admin_can_edit_a_document(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $page = LegalPage::where('slug', 'delivery')->firstOrFail();

        Livewire::test(\App\Filament\Resources\LegalPages\Pages\EditLegalPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title' => 'Доставка, оплата и сроки'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Доставка, оплата и сроки', $page->fresh()->title);
        $this->get('/delivery')->assertOk()->assertSee('Доставка, оплата и сроки');
    }

    /** Адрес документа из админки менять нельзя: на него ссылаются чеки. */
    public function test_admin_cannot_change_a_slug(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $page = LegalPage::where('slug', 'offer')->firstOrFail();

        Livewire::test(\App\Filament\Resources\LegalPages\Pages\EditLegalPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['slug' => 'publichnaya-oferta'])
            ->call('save');

        $this->assertSame('offer', $page->fresh()->slug);
    }

    public function test_admin_cannot_create_documents(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin/legal-pages/create')
            ->assertNotFound();
    }

    /** Непроверенный текст помечается как черновик разработчика. */
    public function test_unreviewed_document_is_marked_as_a_draft(): void
    {
        $page = LegalPage::where('slug', 'offer')->firstOrFail();

        $this->assertTrue($page->isDraft(), 'Свежий текст должен считаться черновиком');

        $page->update(['reviewed_at' => now()]);

        $this->assertFalse($page->fresh()->isDraft());
    }

    /** Повторный запуск сидера не должен затирать правки заказчика. */
    public function test_seeder_keeps_edited_texts(): void
    {
        LegalPage::where('slug', 'offer')->update(['body' => '<p>Проверенный юристом текст</p>']);

        $this->seed(LegalPagesSeeder::class);

        $this->assertSame('<p>Проверенный юристом текст</p>', LegalPage::where('slug', 'offer')->first()->body);
        $this->assertSame(count(LegalPage::SLUGS), LegalPage::count());
    }
}
