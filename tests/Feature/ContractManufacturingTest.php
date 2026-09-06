<?php

namespace Tests\Feature;

use App\Mail\ContractLeadReceived;
use App\Models\LeadRequest;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Контрактное производство: лендинг и заявка.
 *
 * Форма отличается от оптовой — категория продукта и планируемый объём
 * вместо города и формата продаж (docs/07-design-review.md § 8.2).
 */
class ContractManufacturingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** @return array<string, string> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Мария',
            'contact'          => '+7 900 000-00-00',
            'product_category' => 'body',
            'planned_volume'   => '1 000 единиц',
            'comment'          => 'Крем для рук под собственным брендом.',
            'consent'          => '1',
        ], $overrides);
    }

    // ─────────────────────────────────── лендинг

    public function test_page_opens_with_all_blocks_from_the_prototype(): void
    {
        $response = $this->get(route('contract'))->assertOk();

        foreach ([
            'Косметика',
            'под вашим',
            'брендом',
            'Ваша идея',
            'Готовый продукт',
            'Как мы',
            'работаем',
            'Получаем запрос',
            'Согласовываем продукт',
            'Производим тираж',
            'Обсудить',
        ] as $text) {
            $response->assertSee($text, escape: false);
        }
    }

    public function test_form_has_the_fields_from_the_prototype(): void
    {
        $html = $this->get(route('contract'))->assertOk()->getContent();

        foreach (['name="name"', 'name="contact"', 'name="product_category"', 'name="planned_volume"', 'name="consent"'] as $field) {
            $this->assertStringContainsString($field, $html, "В форме нет поля {$field}");
        }

        foreach (LeadRequest::CONTRACT_CATEGORIES as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /** Согласие не предзаполнено: предзаполненная галочка согласием не является. */
    public function test_consent_checkbox_is_not_pre_checked(): void
    {
        $html = $this->get(route('contract'))->assertOk()->getContent();

        $checkbox = substr($html, strpos($html, 'name="consent"') - 60, 160);

        $this->assertStringNotContainsString('checked', $checkbox);
    }

    public function test_cta_leads_to_the_form(): void
    {
        $this->get(route('contract'))->assertOk()->assertSee('href="#contract-request"', escape: false);
    }

    // ─────────────────────────────────── отправка

    public function test_valid_request_is_stored(): void
    {
        Mail::fake();

        $this->post(route('contract.store'), $this->validPayload())
            ->assertRedirect(route('contract', ['sent' => 1]));

        $lead = LeadRequest::sole();

        $this->assertSame(LeadRequest::TYPE_CONTRACT, $lead->type);
        $this->assertSame('Мария', $lead->name);
        $this->assertSame('+7 900 000-00-00', $lead->contact);
        $this->assertSame('body', $lead->product_category);
        $this->assertSame('1 000 единиц', $lead->planned_volume);
        $this->assertSame('Крем для рук под собственным брендом.', $lead->comment);
        $this->assertSame(LeadRequest::STATUS_NEW, $lead->status);
        $this->assertNotNull($lead->consent_at, 'Факт согласия не зафиксирован');
        $this->assertNotNull($lead->ip);
    }

    public function test_success_message_is_shown_after_redirect(): void
    {
        Mail::fake();

        $this->post(route('contract.store'), $this->validPayload())
            ->assertRedirect()
            ->assertSessionHas('lead_sent');

        $this->get(route('contract', ['sent' => 1]))
            ->assertOk()
            ->assertSee('Заявка отправлена')
            ->assertDontSee('name="planned_volume"', escape: false);
    }

    public function test_optional_fields_can_be_empty(): void
    {
        Mail::fake();

        $this->post(route('contract.store'), [
            'name'    => 'Иван',
            'contact' => '@ivan',
            'consent' => '1',
        ])->assertRedirect();

        $lead = LeadRequest::sole();

        $this->assertNull($lead->product_category);
        $this->assertNull($lead->planned_volume);
        $this->assertNull($lead->comment);
    }

    // ─────────────────────────────────── уведомление менеджеру

    public function test_manager_is_notified(): void
    {
        Mail::fake();
        Setting::put('manager_email', 'manager@livsi.shop');

        $this->post(route('contract.store'), $this->validPayload());

        Mail::assertQueued(
            ContractLeadReceived::class,
            fn (ContractLeadReceived $mail) => $mail->hasTo('manager@livsi.shop')
                && $mail->lead->type === LeadRequest::TYPE_CONTRACT,
        );
    }

    /** Без адреса менеджера заявка всё равно должна сохраниться. */
    public function test_lead_survives_a_missing_manager_email(): void
    {
        Mail::fake();
        Setting::put('manager_email', '');
        config(['livsi.manager_email' => null]);

        $this->post(route('contract.store'), $this->validPayload())->assertRedirect();

        $this->assertSame(1, LeadRequest::count());
        Mail::assertNothingQueued();
    }

    public function test_notification_subject_names_the_type(): void
    {
        $lead = LeadRequest::create([
            'name' => 'Тест', 'contact' => '@t', 'type' => LeadRequest::TYPE_CONTRACT, 'consent_at' => now(),
        ]);

        $this->assertStringContainsString(
            'Контрактное производство',
            (new ContractLeadReceived($lead))->envelope()->subject,
        );
    }

    /**
     * Шаблон письма собирается по-настоящему: Mail::fake() вёрстку
     * не рендерит, и ошибка в blade осталась бы незамеченной.
     */
    public function test_notification_body_renders(): void
    {
        $lead = LeadRequest::create([
            'type'             => LeadRequest::TYPE_CONTRACT,
            'name'             => 'Мария',
            'contact'          => '@maria',
            'product_category' => 'hands',
            'planned_volume'   => '1 000 единиц',
            'comment'          => "Первая строка\nвторая строка",
            'consent_at'       => now(),
            'utm'              => ['utm_source' => 'yandex'],
        ]);

        $html = (new ContractLeadReceived($lead))->render();

        $this->assertStringContainsString('Мария', $html);
        $this->assertStringContainsString('@maria', $html);
        $this->assertStringContainsString('Уход за руками и ногами', $html);
        $this->assertStringContainsString('1 000 единиц', $html);
        $this->assertStringContainsString('utm_source', $html);
        $this->assertStringContainsString('/admin/lead-requests/' . $lead->id . '/edit', $html);

        // Переводы строк в описании задачи должны сохраниться.
        $this->assertStringContainsString('<br />', $html);
    }

    /** Письмо не должно падать на заявке без необязательных полей. */
    public function test_notification_body_renders_without_optional_fields(): void
    {
        $lead = LeadRequest::create([
            'type'       => LeadRequest::TYPE_CONTRACT,
            'name'       => 'Иван',
            'contact'    => '@ivan',
            'consent_at' => now(),
        ]);

        $html = (new ContractLeadReceived($lead))->render();

        $this->assertStringContainsString('не указана', $html);
        $this->assertStringContainsString('не указан', $html);
    }

    // ─────────────────────────────────── валидация

    public function test_name_and_contact_are_required(): void
    {
        $this->post(route('contract.store'), $this->validPayload(['name' => '', 'contact' => '']))
            ->assertSessionHasErrors(['name', 'contact']);

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_consent_is_required(): void
    {
        $this->post(route('contract.store'), $this->validPayload(['consent' => null]))
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->post(route('contract.store'), $this->validPayload(['product_category' => 'какая-то-своя']))
            ->assertSessionHasErrors('product_category');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_entered_values_survive_a_validation_error(): void
    {
        $this->from(route('contract'))
            ->post(route('contract.store'), $this->validPayload(['contact' => '']))
            ->assertRedirect(route('contract'));

        $this->from(route('contract'))
            ->followingRedirects()
            ->post(route('contract.store'), $this->validPayload(['contact' => '']))
            ->assertSee('value="Мария"', escape: false);
    }

    /** Приманка для ботов: заполненное скрытое поле — отказ. */
    public function test_filled_honeypot_is_rejected(): void
    {
        $this->post(route('contract.store'), $this->validPayload(['company_website' => 'http://spam.example']))
            ->assertSessionHasErrors('company_website');

        $this->assertSame(0, LeadRequest::count());
    }

    public function test_honeypot_field_is_hidden_from_people(): void
    {
        $html = $this->get(route('contract'))->assertOk()->getContent();

        $position = strpos($html, 'name="company_website"');
        $this->assertNotFalse($position, 'Приманки в форме нет');

        $before = substr($html, 0, $position);
        $this->assertStringContainsString('aria-hidden="true"', substr($before, -400));
    }

    public function test_requests_are_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('contract.store'), $this->validPayload())->assertRedirect();
        }

        $this->post(route('contract.store'), $this->validPayload())->assertStatus(429);

        $this->assertSame(10, LeadRequest::count());
    }

    // ─────────────────────────────────── атрибуция

    public function test_campaign_marks_from_the_landing_reach_the_lead(): void
    {
        Mail::fake();

        $this->get(route('contract', ['utm_source' => 'yandex', 'utm_campaign' => 'contract-2026']))->assertOk();

        $this->post(route('contract.store'), $this->validPayload())->assertRedirect();

        $utm = LeadRequest::sole()->utm;

        $this->assertSame('yandex', $utm['utm_source']);
        $this->assertSame('contract-2026', $utm['utm_campaign']);
        $this->assertArrayHasKey('landing', $utm);
    }

    /** Первый источник не перезаписывается переходом по внутренней ссылке. */
    public function test_first_campaign_source_wins(): void
    {
        Mail::fake();

        $this->get(route('home', ['utm_source' => 'vk']))->assertOk();
        $this->get(route('contract', ['utm_source' => 'telegram']))->assertOk();

        $this->post(route('contract.store'), $this->validPayload())->assertRedirect();

        $this->assertSame('vk', LeadRequest::sole()->utm['utm_source']);
    }

    public function test_lead_without_marks_has_no_utm(): void
    {
        Mail::fake();

        $this->post(route('contract.store'), $this->validPayload())->assertRedirect();

        $this->assertNull(LeadRequest::sole()->utm);
    }

    // ─────────────────────────────────── админка

    public function test_admin_sees_the_lead(): void
    {
        Mail::fake();

        $this->post(route('contract.store'), $this->validPayload());

        $admin = User::factory()->create(['is_admin' => true]);
        $lead  = LeadRequest::sole();

        $this->actingAs($admin)->get('/admin/lead-requests')->assertOk();
        $this->actingAs($admin)->get('/admin/lead-requests/' . $lead->id . '/edit')->assertOk();
    }

    /** Заявки создаются только с сайта: у ручной записи не было бы согласия. */
    public function test_admin_cannot_create_a_lead_by_hand(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/lead-requests/create')->assertNotFound();
    }

    public function test_page_is_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('contract'));
    }
}
