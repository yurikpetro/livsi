<?php

namespace Tests\Feature;

use App\Mail\ContractLeadReceived;
use App\Mail\WholesaleLeadReceived;
use App\Models\LeadRequest;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Оптовое партнёрство: лендинг и заявка на прайс-лист.
 *
 * Поля свои — город и формат продаж вместо категории и объёма. Общее
 * с контрактным производством (согласие, антиспам, атрибуция) вынесено
 * в StoreLeadRequest и проверяется здесь отдельно: правило, ослабленное
 * только в одной из форм, — это дыра, которую легко не заметить.
 */
class PartnersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'         => 'Ольга',
            'contact'      => '+7 900 111-22-33',
            'city'         => 'Ставрополь',
            'sales_format' => 'salon',
            'comment'      => 'Салон маникюра, интересует BASE.',
            'consent'      => '1',
        ], $overrides);
    }

    // ─────────────────────────────────── лендинг

    public function test_page_opens_with_all_blocks_from_the_prototype(): void
    {
        $response = $this->get(route('partners'))->assertOk();

        foreach ([
            'Станьте',
            'партнёром',
            'Опт',
            'Розница',
            'Получить',
            'прайс-лист',
            'Как это',
            'работает',
            'Получаете прайс-лист',
            'Формируете заказ',
            'Продаёте у себя',
        ] as $text) {
            $response->assertSee($text, escape: false);
        }
    }

    public function test_form_has_the_fields_from_the_prototype(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        foreach (['name="name"', 'name="contact"', 'name="city"', 'name="sales_format"', 'name="consent"'] as $field) {
            $this->assertStringContainsString($field, $html, "В форме нет поля {$field}");
        }

        foreach (LeadRequest::SALES_FORMATS as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /** Переключателя оптовых цен на витрине не делаем — решение от 31.08.2026. */
    public function test_page_has_no_price_toggle(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        // Полоса «ОПТ → РОЗНИЦА» декоративная: ни кнопок, ни ссылок в ней быть не должно.
        $position = mb_strpos($html, 'Розница');
        $band     = mb_substr($html, max(0, $position - 400), 500);

        $this->assertStringNotContainsString('<button', $band);
    }

    public function test_consent_checkbox_is_not_pre_checked(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        $checkbox = substr($html, strpos($html, 'name="consent"') - 60, 160);

        $this->assertStringNotContainsString('checked', $checkbox);
    }

    public function test_cta_leads_to_the_form(): void
    {
        $this->get(route('partners'))->assertOk()->assertSee('href="#partner-form"', escape: false);
    }

    public function test_photos_are_shown_not_placeholders(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        $this->assertStringContainsString('img/derived/partners/wholesale', $html);
        $this->assertStringContainsString('img/derived/partners/retail', $html);
        $this->assertStringNotContainsString('Фото оптовой поставки', $html);
    }

    // ─────────────────────────────────── отправка

    public function test_valid_request_is_stored(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), $this->validPayload())
            ->assertRedirect(route('partners', ['sent' => 1]));

        $lead = LeadRequest::sole();

        $this->assertSame(LeadRequest::TYPE_WHOLESALE, $lead->type);
        $this->assertSame('Ольга', $lead->name);
        $this->assertSame('Ставрополь', $lead->city);
        $this->assertSame('salon', $lead->sales_format);
        $this->assertSame('Салон или студия', $lead->salesFormatLabel());
        $this->assertSame(LeadRequest::STATUS_NEW, $lead->status);
        $this->assertNotNull($lead->consent_at, 'Факт согласия не зафиксирован');
        $this->assertNotNull($lead->ip);
    }

    /** Поля контрактной заявки в оптовой остаются пустыми. */
    public function test_wholesale_lead_has_no_contract_fields(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), $this->validPayload())->assertRedirect();

        $lead = LeadRequest::sole();

        $this->assertNull($lead->product_category);
        $this->assertNull($lead->planned_volume);
    }

    public function test_success_message_is_shown_after_redirect(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHas('lead_sent');

        $this->get(route('partners', ['sent' => 1]))
            ->assertOk()
            ->assertSee('Заявка отправлена')
            ->assertDontSee('name="sales_format"', escape: false);
    }

    public function test_optional_fields_can_be_empty(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), [
            'name'    => 'Иван',
            'contact' => '@ivan',
            'consent' => '1',
        ])->assertRedirect();

        $lead = LeadRequest::sole();

        $this->assertNull($lead->city);
        $this->assertNull($lead->sales_format);
        $this->assertNull($lead->comment);
    }

    // ─────────────────────────────────── уведомление

    public function test_manager_gets_a_letter_of_its_own_kind(): void
    {
        Mail::fake();
        Setting::put('manager_email', 'manager@livsi.shop');

        $this->post(route('partners.store'), $this->validPayload());

        Mail::assertQueued(
            WholesaleLeadReceived::class,
            fn (WholesaleLeadReceived $mail) => $mail->hasTo('manager@livsi.shop')
                && $mail->lead->type === LeadRequest::TYPE_WHOLESALE,
        );

        // Письмо контрактного производства уходить не должно.
        Mail::assertNotQueued(ContractLeadReceived::class);
    }

    public function test_notification_subject_names_the_type(): void
    {
        $lead = LeadRequest::create([
            'name' => 'Тест', 'contact' => '@t',
            'type' => LeadRequest::TYPE_WHOLESALE, 'consent_at' => now(),
        ]);

        $this->assertStringContainsString(
            'Оптовое партнёрство',
            (new WholesaleLeadReceived($lead))->envelope()->subject,
        );
    }

    /** Mail::fake() вёрстку не рендерит — собираем письмо по-настоящему. */
    public function test_notification_body_renders(): void
    {
        $lead = LeadRequest::create([
            'type'         => LeadRequest::TYPE_WHOLESALE,
            'name'         => 'Ольга',
            'contact'      => '@olga',
            'city'         => 'Ставрополь',
            'sales_format' => 'marketplaces',
            'comment'      => "Первая строка\nвторая строка",
            'consent_at'   => now(),
            'utm'          => ['utm_source' => 'vk'],
        ]);

        $html = (new WholesaleLeadReceived($lead))->render();

        $this->assertStringContainsString('Ольга', $html);
        $this->assertStringContainsString('Ставрополь', $html);
        $this->assertStringContainsString('Маркетплейсы', $html);
        $this->assertStringContainsString('utm_source', $html);
        $this->assertStringContainsString('<br />', $html);
        $this->assertStringContainsString('/admin/lead-requests/' . $lead->id . '/edit', $html);
    }

    public function test_notification_body_renders_without_optional_fields(): void
    {
        $lead = LeadRequest::create([
            'type' => LeadRequest::TYPE_WHOLESALE, 'name' => 'Иван',
            'contact' => '@ivan', 'consent_at' => now(),
        ]);

        $html = (new WholesaleLeadReceived($lead))->render();

        $this->assertStringContainsString('не указан', $html);
    }

    public function test_lead_survives_a_missing_manager_email(): void
    {
        Mail::fake();
        Setting::put('manager_email', '');
        config(['livsi.manager_email' => null]);

        $this->post(route('partners.store'), $this->validPayload())->assertRedirect();

        $this->assertSame(1, LeadRequest::count());
        Mail::assertNothingQueued();
    }

    // ─────────────────────────────────── валидация и защита

    public function test_name_and_contact_are_required(): void
    {
        $this->post(route('partners.store'), $this->validPayload(['name' => '', 'contact' => '']))
            ->assertSessionHasErrors(['name', 'contact']);

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_consent_is_required(): void
    {
        $this->post(route('partners.store'), $this->validPayload(['consent' => null]))
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_unknown_sales_format_is_rejected(): void
    {
        $this->post(route('partners.store'), $this->validPayload(['sales_format' => 'своё-значение']))
            ->assertSessionHasErrors('sales_format');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_filled_honeypot_is_rejected(): void
    {
        $this->post(route('partners.store'), $this->validPayload(['company_website' => 'http://spam.example']))
            ->assertSessionHasErrors('company_website');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_entered_values_survive_a_validation_error(): void
    {
        $this->from(route('partners'))
            ->followingRedirects()
            ->post(route('partners.store'), $this->validPayload(['contact' => '']))
            ->assertSee('value="Ольга"', escape: false)
            ->assertSee('value="Ставрополь"', escape: false);
    }

    public function test_requests_are_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('partners.store'), $this->validPayload())->assertRedirect();
        }

        $this->post(route('partners.store'), $this->validPayload())->assertStatus(429);

        $this->assertSame(10, LeadRequest::count());
    }

    public function test_campaign_marks_reach_the_lead(): void
    {
        Mail::fake();

        $this->get(route('partners', ['utm_source' => 'vk', 'utm_campaign' => 'opt-2026']))->assertOk();
        $this->post(route('partners.store'), $this->validPayload())->assertRedirect();

        $utm = LeadRequest::sole()->utm;

        $this->assertSame('vk', $utm['utm_source']);
        $this->assertSame('opt-2026', $utm['utm_campaign']);
    }

    // ─────────────────────────────────── админка

    public function test_admin_sees_the_wholesale_lead_with_its_own_fields(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), $this->validPayload());

        $admin = User::factory()->create(['is_admin' => true]);
        $lead  = LeadRequest::sole();

        $this->actingAs($admin)->get('/admin/lead-requests/' . $lead->id . '/edit')->assertOk();

        // Значения полей Filament держит в состоянии Livewire, а не в HTML,
        // поэтому проверяем через компонент, а не поиском по разметке.
        \Livewire\Livewire::test(
            \App\Filament\Resources\LeadRequests\Pages\EditLeadRequest::class,
            ['record' => $lead->id],
        )
            ->assertFormSet(['city' => 'Ставрополь'])
            // Формат продаж выводится названием, а не кодом «salon».
            ->assertSee('Салон или студия')
            ->assertDontSee('salon');
    }

    public function test_admin_list_separates_the_two_types(): void
    {
        Mail::fake();

        $this->post(route('partners.store'), $this->validPayload());
        $this->post(route('contract.store'), [
            'name' => 'Пётр', 'contact' => '@petr', 'consent' => '1',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/lead-requests')->assertOk()->assertSee('Оптовые');

        $this->assertSame(1, LeadRequest::where('type', LeadRequest::TYPE_WHOLESALE)->count());
        $this->assertSame(1, LeadRequest::where('type', LeadRequest::TYPE_CONTRACT)->count());
    }

    public function test_page_is_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('partners'));
    }

    // ─────────────────────────────────── соответствие прототипу

    /**
     * Вёрстка блоков собрана общими классами из прототипа.
     *
     * Расхождения заказчик заметил визуально: у надзаголовка заявки не было
     * неоновой подложки, у формы — рамки, поля шли одной колонкой вместо двух.
     * Значения держатся на этих классах, поэтому проверяем именно их.
     */
    public function test_layout_uses_the_prototype_building_blocks(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        foreach ([
            'page-hero'       => 'первый экран',
            'page-hero-title' => 'заголовок первого экрана',
            'model-line'      => 'полоса «от → к»',
            'step-list'       => 'список этапов',
            'section-title'   => 'заголовок секции этапов',
            'lead-section'    => 'секция заявки',
            'lead-layout'     => 'колонки секции заявки',
            'lead-eyebrow'    => 'надзаголовок на неоновой подложке',
            'lead-form'       => 'форма в рамке',
        ] as $class => $what) {
            $this->assertStringContainsString($class, $html, "Нет класса {$class} — {$what}");
        }
    }

    /** Подпись под фотографией — белая полоса с номером в зелёном квадрате. */
    public function test_photo_captions_match_the_prototype(): void
    {
        $html = $this->get(route('partners'))->assertOk()->getContent();

        $this->assertStringContainsString('<figcaption', $html);
        $this->assertStringContainsString('bg-neon', $html);

        // Кадры фиксированной высоты: 380px на мобильном, 560px на десктопе.
        $this->assertStringContainsString('h-[380px]', $html);
        $this->assertStringContainsString('md:h-[560px]', $html);
    }
}
