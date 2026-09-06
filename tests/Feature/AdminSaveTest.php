<?php

namespace Tests\Feature;

use App\Models\LeadRequest;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\ProductVariant;
use App\Models\PromoRule;
use App\Models\Purpose;
use App\Models\Review;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Сохранение через админку.
 *
 * До этих тестов проверялось только то, что страницы открываются. Из-за этого
 * в форме товара незамеченным жило поле `documents` — колонку удалила миграция
 * 000011, и любое сохранение товара падало с SQL-ошибкой. Открытие страницы
 * такую поломку не ловит: она проявляется ровно в момент записи.
 */
class AdminSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        $this->admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($this->admin);
    }

    // ─────────────────────────────────── товар

    public function test_product_can_be_saved(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['title' => 'Скраб для тела обновлённый'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Скраб для тела обновлённый', $product->fresh()->title);
    }

    /** Назначения и задачи — две из трёх осей навигации, их не было в форме вовсе. */
    public function test_product_purposes_and_tasks_can_be_set(): void
    {
        $product  = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $purposes = Purpose::limit(2)->pluck('id')->all();
        $tasks    = Task::limit(2)->pluck('id')->all();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['purposes' => $purposes, 'tasks' => $tasks])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing($purposes, $product->fresh()->purposes->pluck('id')->all());
        $this->assertEqualsCanonicalizing($tasks, $product->fresh()->tasks->pluck('id')->all());
    }

    public function test_product_line_is_chosen_from_a_list_not_typed_as_a_number(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $line    = ProductLine::where('code', 'warm')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['product_line_id' => $line->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($line->id, $product->fresh()->product_line_id);
    }

    /** Поисковый текст пересобирается после сохранения из админки. */
    public function test_saving_a_product_rebuilds_its_search_text(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['title' => 'Кокосовый скраб'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('кокосовый', $product->fresh()->search_text);
    }

    public function test_product_slug_must_be_unique(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $other   = Product::where('slug', '!=', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['slug' => $other->slug])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    /** Оценка без площадки-источника на витрину не попадёт, поэтому и сохранять её нельзя. */
    public function test_rating_requires_its_source(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Products\Pages\EditProduct::class, ['record' => $product->id])
            ->fillForm(['rating' => 4.8, 'reviews_count' => 10, 'reviews_source' => null])
            ->call('save')
            ->assertHasFormErrors(['reviews_source']);
    }

    public function test_new_product_gets_a_slug_from_its_title(): void
    {
        Livewire::test(\App\Filament\Resources\Products\Pages\CreateProduct::class)
            ->fillForm(['title' => 'Пенка для рук'])
            ->assertFormSet(fn (array $state) => filled($state['slug']));
    }

    // ─────────────────────────────────── вариант и цена

    /**
     * Цена в базе — копейки, в админке рубли. Сгенерированная форма
     * показывала копейки с префиксом «$»: введённые 990 превратились бы
     * в 9 рублей 90 копеек.
     */
    public function test_variant_price_is_entered_in_rubles(): void
    {
        $variant = ProductVariant::firstOrFail();

        Livewire::test(\App\Filament\Resources\ProductVariants\Pages\EditProductVariant::class, ['record' => $variant->id])
            // В поле должны стоять рубли записи, а не её копейки.
            ->assertFormSet(fn (array $state) => (float) $state['price'] === $variant->price / 100)
            ->fillForm(['price' => '1 249,50'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(124950, $variant->fresh()->price);
    }

    public function test_variant_can_be_saved_with_dimensions(): void
    {
        $variant = ProductVariant::firstOrFail();

        Livewire::test(\App\Filament\Resources\ProductVariants\Pages\EditProductVariant::class, ['record' => $variant->id])
            ->fillForm(['weight_g' => 250, 'length_mm' => 60, 'width_mm' => 60, 'height_mm' => 180])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(250, $variant->fresh()->weight_g);
    }

    /** Сумму пишут по-русски: с пробелом в разрядах и запятой. */
    public function test_price_accepts_russian_number_format(): void
    {
        $variant = ProductVariant::firstOrFail();

        foreach (['990' => 99000, '1990.5' => 199050, '1 990,50' => 199050] as $typed => $expected) {
            Livewire::test(\App\Filament\Resources\ProductVariants\Pages\EditProductVariant::class, ['record' => $variant->id])
                ->fillForm(['price' => (string) $typed])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame($expected, $variant->fresh()->price, "Не разобрана сумма «{$typed}»");
        }
    }

    public function test_price_rejects_nonsense(): void
    {
        $variant = ProductVariant::firstOrFail();

        Livewire::test(\App\Filament\Resources\ProductVariants\Pages\EditProductVariant::class, ['record' => $variant->id])
            ->fillForm(['price' => 'бесплатно'])
            ->call('save')
            ->assertHasFormErrors(['price']);
    }

    public function test_variant_sku_must_be_unique(): void
    {
        $variant = ProductVariant::firstOrFail();
        $other   = ProductVariant::where('id', '!=', $variant->id)->firstOrFail();

        Livewire::test(\App\Filament\Resources\ProductVariants\Pages\EditProductVariant::class, ['record' => $variant->id])
            ->fillForm(['sku' => $other->sku])
            ->call('save')
            ->assertHasFormErrors(['sku']);
    }

    // ─────────────────────────────────── промо

    public function test_promo_threshold_is_entered_in_rubles(): void
    {
        $rule = PromoRule::where('type', 'gift')->firstOrFail();

        Livewire::test(\App\Filament\Resources\PromoRules\Pages\EditPromoRule::class, ['record' => $rule->id])
            ->assertFormSet(fn (array $state) => (float) $state['threshold'] === $rule->threshold / 100)
            ->fillForm(['threshold' => '4500'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(450000, $rule->fresh()->threshold);
    }

    /** Подарки лежат в связи, а не в JSON-колонке payload. */
    public function test_promo_gifts_can_be_chosen(): void
    {
        $rule     = PromoRule::where('type', 'gift')->firstOrFail();
        $variants = ProductVariant::limit(2)->pluck('id')->all();

        Livewire::test(\App\Filament\Resources\PromoRules\Pages\EditPromoRule::class, ['record' => $rule->id])
            ->fillForm(['gifts' => $variants])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing($variants, $rule->fresh()->gifts->pluck('id')->all());
    }

    // ─────────────────────────────────── прочие сущности

    /**
     * Композиция блока задана макетом: карточек ровно три, и они
     * не создаются и не удаляются из админки — правится только наполнение.
     */
    public function test_review_content_can_be_edited(): void
    {
        $review = Review::where('slot', 'main')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Reviews\Pages\EditReview::class, ['record' => $review->id])
            ->fillForm([
                'text'         => 'Беру уже третий раз.',
                'author'       => 'Ольга',
                'caption'      => 'FRESH / Пенка',
                'role_caption' => 'Постоянный покупатель',
                'rating'       => 4,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $review->fresh();

        $this->assertSame('Беру уже третий раз.', $fresh->text);
        $this->assertSame('Ольга', $fresh->author);
        $this->assertSame('Постоянный покупатель', $fresh->role_caption);
        $this->assertSame(4, $fresh->rating);

        // Место в блоке правкой наполнения не меняется.
        $this->assertSame('main', $fresh->slot);
    }

    public function test_reviews_cannot_be_created_or_deleted(): void
    {
        $this->assertFalse(\App\Filament\Resources\Reviews\ReviewResource::canCreate());
        $this->assertFalse(\App\Filament\Resources\Reviews\ReviewResource::canDeleteAny());

        $this->get('/admin/reviews/create')->assertNotFound();
    }

    public function test_review_text_and_author_are_required(): void
    {
        $review = Review::where('slot', 'top')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Reviews\Pages\EditReview::class, ['record' => $review->id])
            ->fillForm(['text' => '', 'author' => ''])
            ->call('save')
            ->assertHasFormErrors(['text', 'author']);
    }

    /** Длинная цитата не помещается в карточку, поэтому ограничена. */
    public function test_review_text_is_limited_to_what_fits(): void
    {
        $review = Review::where('slot', 'bottom')->firstOrFail();

        Livewire::test(\App\Filament\Resources\Reviews\Pages\EditReview::class, ['record' => $review->id])
            ->fillForm(['text' => str_repeat('а', 400)])
            ->call('save')
            ->assertHasFormErrors(['text']);
    }

    public function test_reviews_list_shows_the_three_slots(): void
    {
        $html = $this->get('/admin/reviews')->assertOk()->getContent();

        foreach (Review::SLOTS as $label) {
            $this->assertStringContainsString($label, $html, "В списке нет места «{$label}»");
        }
    }

    public function test_average_score_is_editable_in_settings(): void
    {
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->fillForm(['reviews_score' => '4.8', 'reviews_score_note' => 'По отзывам на Ozon'])
            ->call('save');

        $this->assertSame('4.8', Setting::get('reviews_score'));
        $this->assertSame('По отзывам на Ozon', Setting::get('reviews_score_note'));
    }

    public function test_product_line_can_be_saved(): void
    {
        $line = ProductLine::where('code', 'fresh')->firstOrFail();

        Livewire::test(\App\Filament\Resources\ProductLines\Pages\EditProductLine::class, ['record' => $line->id])
            ->fillForm(['subtitle' => 'Свежесть и чистота'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Свежесть и чистота', $line->fresh()->subtitle);
    }

    public function test_purpose_code_must_be_unique(): void
    {
        $purpose = Purpose::firstOrFail();
        $other   = Purpose::where('id', '!=', $purpose->id)->firstOrFail();

        Livewire::test(\App\Filament\Resources\Purposes\Pages\EditPurpose::class, ['record' => $purpose->id])
            ->fillForm(['code' => $other->code])
            ->call('save')
            ->assertHasFormErrors(['code']);
    }

    public function test_lead_status_and_comment_can_be_changed(): void
    {
        $lead = LeadRequest::create([
            'type' => LeadRequest::TYPE_CONTRACT, 'name' => 'Пётр',
            'contact' => '@petr', 'consent_at' => now(),
        ]);

        Livewire::test(\App\Filament\Resources\LeadRequests\Pages\EditLeadRequest::class, ['record' => $lead->id])
            ->fillForm(['status' => LeadRequest::STATUS_IN_PROGRESS, 'manager_comment' => 'Позвонил, ждёт КП'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $lead->fresh();

        $this->assertSame(LeadRequest::STATUS_IN_PROGRESS, $fresh->status);
        $this->assertSame('Позвонил, ждёт КП', $fresh->manager_comment);
    }

    /** Содержимое заявки менеджер править не может: нужен исходный текст клиента. */
    public function test_lead_content_stays_untouched(): void
    {
        $lead = LeadRequest::create([
            'type' => LeadRequest::TYPE_CONTRACT, 'name' => 'Пётр',
            'contact' => '@petr', 'consent_at' => now(),
        ]);

        Livewire::test(\App\Filament\Resources\LeadRequests\Pages\EditLeadRequest::class, ['record' => $lead->id])
            ->fillForm(['name' => 'Подменённое имя', 'status' => LeadRequest::STATUS_DONE])
            ->call('save');

        $this->assertSame('Пётр', $lead->fresh()->name);
    }

    // ─────────────────────────────────── настройки

    public function test_settings_page_opens(): void
    {
        $this->get('/admin/settings')->assertOk();
    }

    public function test_thresholds_are_shown_in_rubles_and_saved_in_kopecks(): void
    {
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->assertFormSet(['free_shipping_threshold' => '1000', 'gift_threshold' => '5000'])
            ->fillForm(['free_shipping_threshold' => '1500', 'gift_threshold' => '3000'])
            ->call('save');

        $this->assertSame(150000, Setting::get('free_shipping_threshold'));
        $this->assertSame(300000, Setting::get('gift_threshold'));
    }

    public function test_manager_email_can_be_set_from_settings(): void
    {
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->fillForm(['manager_email' => 'zayavki@livsi.shop'])
            ->call('save');

        $this->assertSame('zayavki@livsi.shop', Setting::get('manager_email'));
    }

    public function test_invalid_manager_email_is_rejected(): void
    {
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->fillForm(['manager_email' => 'не-адрес'])
            ->call('save')
            ->assertHasFormErrors(['manager_email']);
    }

    /** Изменённый порог сразу виден на витрине: настройки читает промо-сервис. */
    public function test_changed_threshold_reaches_the_storefront(): void
    {
        Livewire::test(\App\Filament\Pages\Settings::class)
            ->fillForm(['free_shipping_threshold' => '2000'])
            ->call('save');

        $this->assertSame(200000, Setting::get('free_shipping_threshold'));
    }

    public function test_settings_page_is_closed_to_non_admins(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/settings')
            ->assertForbidden();
    }

    // ─────────────────────────────────── дашборд и загрузки

    public function test_dashboard_shows_the_shop_overview(): void
    {
        Livewire::test(\App\Filament\Widgets\ShopOverview::class)
            ->assertSee('Новых заявок')
            ->assertSee('Без веса и габаритов');
    }

    /**
     * Каждое поле загрузки обязано указывать диск явно.
     *
     * По умолчанию Filament грузит на `local`, откуда сайт файлы не отдаёт:
     * заказчик загрузил бы фото, а в каталоге увидел битую картинку.
     */
    public function test_every_upload_field_declares_its_disk(): void
    {
        $files = glob(app_path('Filament/**/*.php'), GLOB_BRACE) ?: [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $offenders = [];

        foreach (array_unique($files) as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all('/FileUpload::make\((.*?)\)(.*?);/s', $source, $matches);

            foreach ($matches[0] as $declaration) {
                if (! str_contains($declaration, "->disk(")) {
                    $offenders[] = basename($path);
                }
            }
        }

        $this->assertSame([], $offenders, 'Поле загрузки без явного диска: ' . implode(', ', $offenders));
    }
}
