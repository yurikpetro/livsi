<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            'декларации'        => ['/admin/declarations'],
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

    // ─────────────────────────────────── администратор из сидера

    /**
     * Администратор должен появляться из сидера, а не создаваться руками:
     * после `migrate:fresh --seed` в админку было не войти именно поэтому.
     */
    public function test_seeder_creates_a_working_administrator(): void
    {
        config([
            'livsi.admin.email'    => 'admin@example.test',
            'livsi.admin.password' => 'secret-pass',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('secret-pass', $admin->password), 'Пароль администратора не совпадает');
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_seeder_can_be_run_twice_without_duplicating(): void
    {
        config([
            'livsi.admin.email'    => 'admin@example.test',
            'livsi.admin.password' => 'secret-pass',
        ]);

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@example.test')->count());
    }

    /** Без данных в окружении администратор не создаётся: общеизвестный пароль хуже, чем никакой. */
    public function test_seeder_creates_nobody_without_credentials(): void
    {
        config(['livsi.admin.email' => null, 'livsi.admin.password' => null]);

        $before = User::count();

        $this->seed(AdminUserSeeder::class);

        $this->assertSame($before, User::count());
    }

    /** На боевом контуре пароля по умолчанию быть не должно. */
    public function test_no_default_password_outside_development(): void
    {
        $this->assertNotNull(
            (require base_path('config/livsi.php'))['admin']['password'],
            'В окружении разработки пароль подставляется',
        );

        // env() читает $_ENV и $_SERVER, а не putenv().
        $original = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';

        try {
            $production = require base_path('config/livsi.php');
        } finally {
            if ($original === null) {
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $original;
            }
        }

        $this->assertNull($production['admin']['email']);
        $this->assertNull($production['admin']['password']);
    }
}
