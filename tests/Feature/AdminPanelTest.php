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
        $this->seed(\Database\Seeders\LegalPagesSeeder::class);

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
            ->get(\App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product]))
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

    /**
     * На боевом контуре значение по умолчанию не подставляется.
     *
     * Проверяется именно запасное значение: если ADMIN_EMAIL и ADMIN_PASSWORD
     * заданы в окружении осознанно, они и должны работать — это нормальная
     * настройка продакшена. Опасно другое: чтобы туда не уехал пароль
     * из публичного README.
     */
    public function test_no_default_credentials_outside_development(): void
    {
        // env() читает $_ENV и $_SERVER, а не putenv().
        $saved = [];

        foreach (['APP_ENV', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $key) {
            $saved[$key] = $_ENV[$key] ?? null;
        }

        // Значение может лежать в трёх местах сразу: Dotenv кладёт его
        // и в суперглобальные массивы, и через putenv.
        $forget = function (string $key): void {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        };

        try {
            // Разработка: запасные значения есть, иначе локально не войти.
            $forget('ADMIN_EMAIL');
            $forget('ADMIN_PASSWORD');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';

            $local = require base_path('config/livsi.php');

            $this->assertNotNull($local['admin']['email']);
            $this->assertNotNull($local['admin']['password']);

            // Продакшен: тех же запасных значений быть не должно.
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';

            $production = require base_path('config/livsi.php');

            $this->assertNull($production['admin']['email']);
            $this->assertNull($production['admin']['password']);
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    $forget($key);
                } else {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }

}
