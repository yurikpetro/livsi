<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Livewire\QuickView;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Purpose;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfessionalProductLinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    #[DataProvider('professionalLines')]
    public function test_admin_can_create_a_product_in_each_professional_line(string $code, string $title): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $line = ProductLine::where('code', $code)->firstOrFail();

        Livewire::test(CreateProduct::class)
            ->assertFormFieldDoesNotExist('is_pro')
            ->assertFormFieldExists('product_line_id', fn (Select $field): bool => $field->getLabel() === 'Линейка'
                && count($field->getOptions()) === 6 && $field->getOptions()[$line->id] === $title)
            ->assertFormFieldExists('badge', fn (Select $field): bool => ! array_key_exists('pro', $field->getOptions()))
            ->fillForm(['title' => 'Новый товар '.$title, 'slug' => 'new-'.$code, 'short_description' => 'Товар профессиональной линейки', 'product_line_id' => $line->id])
            ->call('create')->assertHasNoFormErrors();

        $product = Product::where('slug', 'new-'.$code)->firstOrFail();
        $this->assertSame($line->id, $product->product_line_id);
        $this->assertTrue($product->is_pro);
        $this->get(route('catalog.index', ['line' => $code]))->assertOk()->assertSee($product->title);
        $this->get(route('catalog.line', $line))->assertOk()->assertSee($product->title)->assertSee('Линейка');
        $this->get(route('catalog.show', $product))->assertOk()
            ->assertSee('href="'.route('catalog.line', $line).'"', false);
        $otherCode = $code === 'pro-skin' ? 'pro-manicure-pedicure' : 'pro-skin';
        $this->get(route('catalog.index', ['line' => $otherCode]))->assertOk()->assertDontSee($product->title);

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id)->assertSee($title);
    }

    public function test_changing_and_clearing_the_line_updates_professional_collections(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $product = Product::where('slug', 'keratoliticheskiy-gel-dlya-stop')->firstOrFail();

        foreach (['pro-skin' => true, 'fresh' => false, '' => false] as $code => $professional) {
            $lineId = ProductLine::where('code', $code)->value('id');
            Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
                ->assertFormFieldDoesNotExist('is_pro')
                ->fillForm(['product_line_id' => $lineId])->call('save')->assertHasNoFormErrors();

            $this->assertSame($lineId, $product->fresh()->product_line_id);
            $this->assertSame($professional, $product->fresh()->is_pro);
            $this->assertSame($professional, Product::professional()->whereKey($product->id)->exists());
        }

        Livewire::test(ListProducts::class)->assertTableColumnDoesNotExist('is_pro');
    }

    public function test_professional_lines_work_without_the_legacy_flag_or_purpose_assignments(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        DB::table('products')->where('id', $product->id)->update([
            'product_line_id' => ProductLine::where('code', 'pro-skin')->value('id'),
            'is_pro' => false,
        ]);
        $product->purposes()->detach();

        $this->get('/catalog?line=pro-skin')->assertOk()->assertSee($product->title);
        $this->get('/catalog?tab=pro')->assertOk()->assertSee($product->title);
        $this->get('/pro')->assertOk()->assertSee($product->title);
        $this->get('/catalog?tab=care')->assertOk()->assertDontSee($product->title);
        $home = $this->get('/')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$home);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//article[@data-shelf-line="pro"]//button[@aria-label="Быстрый просмотр: '.$product->title.'"]')->length);
    }

    #[DataProvider('professionalLines')]
    public function test_public_navigation_links_to_the_line(string $code, string $title): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//div[@id="catalog-sub-pro"]/a[normalize-space()="'.$title.'"] | //nav[@id="mobile-nav"]//a[normalize-space()="'.$title.'"] | //a[contains(@class,"hero-pro-cta") and starts-with(normalize-space(),"'.$title.'")]');

        $this->assertSame(3, $links->length);
        foreach ($links as $link) {
            $this->assertSame(route('catalog.index', ['line' => $code]), $link->getAttribute('href'));
        }
        $this->assertSame(4, $xpath->query('//div[@id="catalog-sub-care"]/a')->length);
    }

    public function test_old_professional_category_links_redirect_and_preserve_search_and_sort(): void
    {
        foreach ([
            'pro-skin' => ['face', 'body'],
            'pro-manicure-pedicure' => ['manicure', 'pedicure'],
        ] as $code => $purposes) {
            $this->get(route('catalog.index', [
                'tab' => 'pro', 'purpose' => $purposes, 'q' => 'крем', 'sort' => 'price_desc',
            ]))->assertStatus(301)->assertRedirect(route('catalog.index', [
                'q' => 'крем', 'sort' => 'price_desc', 'line' => $code,
            ]));
        }
    }

    public function test_migration_transfers_only_unambiguous_legacy_products_and_preserves_custom_data(): void
    {
        $skin = $this->legacyProduct('legacy-skin', ['face'], 'new');
        $nails = $this->legacyProduct('legacy-nails', ['pedicure'], 'pro');
        $ambiguous = $this->legacyProduct('legacy-ambiguous', ['face', 'pedicure'], 'pro');
        $unknown = $this->legacyProduct('legacy-unknown', [], 'pro');
        $unchanged = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $original = $unchanged->getAttributes();
        $line = ProductLine::where('code', 'pro-skin')->firstOrFail();
        $line->update(['title' => 'Моя PRO SKIN', 'color_bg' => '#abcdef', 'is_active' => false]);

        $migration = require database_path('migrations/2026_11_02_073926_add_professional_product_lines.php');
        $migration->up();
        $migration->up();

        $this->assertSame($line->id, $skin->fresh()->product_line_id);
        $this->assertSame('pro-manicure-pedicure', $nails->fresh()->line->code);
        $this->assertSame('new', $skin->fresh()->badge);
        $this->assertNull($nails->fresh()->badge);
        $this->assertStringContainsString('pro manicure / pedicure', $nails->fresh()->search_text);
        foreach ([$ambiguous, $unknown] as $product) {
            $this->assertNull($product->fresh()->product_line_id);
            $this->assertNull($product->fresh()->badge);
            $this->assertTrue($product->fresh()->is_pro);
            $this->assertTrue(Product::professional()->whereKey($product->id)->exists());
        }
        $this->assertSame($original, $unchanged->fresh()->getAttributes());
        $this->assertSame('Моя PRO SKIN', $line->fresh()->title);
        $this->assertSame('#abcdef', $line->fresh()->color_bg);
        $this->assertFalse($line->fresh()->is_active);
        $this->assertSame(6, ProductLine::count());
        $this->assertSame('Внутренний артикул', $skin->fresh()->sku);
        $this->assertSame(1, $skin->purposes()->count());
    }

    public function test_migration_adds_the_missing_lines_without_reseeding_the_catalog(): void
    {
        ProductLine::whereIn('code', ProductLine::PROFESSIONAL_CODES)->delete();
        $productsCount = Product::count();

        $migration = require database_path('migrations/2026_11_02_073926_add_professional_product_lines.php');
        $migration->up();

        $this->assertSame([
            'fresh', 'sweet', 'warm', 'base', 'pro-skin', 'pro-manicure-pedicure',
        ], ProductLine::orderBy('sort')->pluck('code')->all());
        $this->assertSame($productsCount, Product::count());
    }

    /** @return array<string, array{string, string}> */
    public static function professionalLines(): array
    {
        return [
            'skin' => ['pro-skin', 'PRO SKIN'],
            'nails' => ['pro-manicure-pedicure', 'PRO MANICURE / PEDICURE'],
        ];
    }

    /** @param array<string> $purposes */
    private function legacyProduct(string $slug, array $purposes, string $badge): Product
    {
        $product = Product::create(['slug' => $slug, 'title' => $slug]);
        DB::table('products')->where('id', $product->id)->update(['is_pro' => true, 'badge' => $badge, 'sku' => $slug === 'legacy-skin' ? 'Внутренний артикул' : null]);
        $product->purposes()->sync(Purpose::whereIn('code', $purposes)->pluck('id'));

        return $product->fresh();
    }
}
