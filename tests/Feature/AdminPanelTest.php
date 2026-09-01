<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Дымовые тесты админки: каждая страница должна открываться без ошибок.
 * Именно здесь ловятся опечатки в именах колонок и связей — сгенерированные
 * ресурсы Filament падают только в рантайме.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_page_is_available(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_dashboard_opens_for_authenticated_user(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    }

    #[DataProvider('resourcePages')]
    public function test_resource_index_pages_open(string $url): void
    {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public static function resourcePages(): array
    {
        return [
            'товары'            => ['/admin/products'],
            'варианты'          => ['/admin/product-variants'],
            'линейки'           => ['/admin/product-lines'],
            'назначения'        => ['/admin/purposes'],
            'задачи'            => ['/admin/tasks'],
            'квота склада'      => ['/admin/stock-quotas'],
            'промо-правила'     => ['/admin/promo-rules'],
            'заявки'            => ['/admin/lead-requests'],
            'отзывы'            => ['/admin/reviews'],
            'галерея'           => ['/admin/ugc-items'],
            'вопросы и ответы'  => ['/admin/faq-items'],
            'реквизиты'         => ['/admin/seller-profiles'],
        ];
    }

    public function test_product_edit_page_opens_with_variants_relation(): void
    {
        $product = \App\Models\Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/products/' . $product->id . '/edit')
            ->assertOk();
    }
}
