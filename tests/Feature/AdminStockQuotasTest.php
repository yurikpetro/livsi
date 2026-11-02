<?php

namespace Tests\Feature;

use App\Filament\Resources\StockQuotas\Pages\CreateStockQuota;
use App\Filament\Resources\StockQuotas\Pages\EditStockQuota;
use App\Filament\Resources\StockQuotas\Pages\ListStockQuotas;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockQuota;
use App\Models\StockQuotaMovement;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AdminStockQuotasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_new_variant_without_quota_is_visible_without_creating_stock_on_read(): void
    {
        $variant = $this->newVariant('NEW-STOCK');
        $count = StockQuota::count();

        Livewire::test(ListStockQuotas::class)
            ->assertCanSeeTableRecords([$variant])
            ->assertSee('NEW-STOCK')
            ->assertTableColumnStateSet('quota.allocated', 0, $variant)
            ->assertTableColumnStateSet('available', 0, $variant);

        $this->assertSame($count, StockQuota::count());
        $this->assertNull($variant->fresh()->quota);
    }

    public function test_first_replenishment_creates_quota_and_repeated_replenishment_keeps_same_quota(): void
    {
        $variant = $this->newVariant('NEW-STOCK');
        $component = Livewire::test(ListStockQuotas::class)
            ->callAction(TestAction::make('replenish')->table($variant), data: ['qty' => 7, 'comment' => 'Первый приход'])
            ->assertHasNoActionErrors()
            ->assertTableColumnStateSet('quota.allocated', 7, $variant)
            ->assertTableColumnStateSet('available', 7, $variant);
        $quota = $variant->fresh()->quota;
        $this->assertNotNull($quota);
        $this->assertSame(7, $quota->allocated);
        $this->assertSame(7, $quota->available());
        $this->assertSame(0, $quota->reserved);
        $this->assertSame(0, $quota->sold);
        $this->assertNotNull($quota->allocated_at);
        $this->assertDatabaseHas('stock_quota_movements', [
            'stock_quota_id' => $quota->id, 'type' => 'allocate', 'qty' => 7,
            'comment' => 'Первый приход', 'user_id' => auth()->id(),
        ]);

        $component->callAction(TestAction::make('replenish')->table($variant), data: ['qty' => 3])
            ->assertHasNoActionErrors();

        $this->assertSame(10, $quota->fresh()->allocated);
        $this->assertSame(1, StockQuota::where('product_variant_id', $variant->id)->count());
        $this->assertSame(2, $quota->movements()->count());
    }

    public function test_replenishment_preserves_reservations_and_sales(): void
    {
        $quota = StockQuota::firstOrFail();
        $quota->update(['allocated' => 20, 'reserved' => 3, 'sold' => 4]);

        Livewire::test(ListStockQuotas::class)
            ->callAction(TestAction::make('replenish')->table($quota->variant), data: ['qty' => 5])
            ->assertHasNoActionErrors();

        $this->assertSame([25, 3, 4, 18], [$quota->fresh()->allocated, $quota->fresh()->reserved, $quota->fresh()->sold, $quota->fresh()->available()]);
    }

    #[TestWith([0])]
    #[TestWith([-2])]
    #[TestWith([1.5])]
    public function test_invalid_replenishment_does_not_create_quota(int|float $quantity): void
    {
        $variant = $this->newVariant('INVALID-STOCK');

        Livewire::test(ListStockQuotas::class)
            ->callAction(TestAction::make('replenish')->table($variant), data: ['qty' => $quantity])
            ->assertHasActionErrors(['qty']);

        $this->assertNull($variant->fresh()->quota);
    }

    public function test_activity_filter_uses_product_activity_and_includes_missing_quotas(): void
    {
        $active = $this->newVariant('ACTIVE-STOCK', true);
        $active->update(['is_active' => false]);
        $inactive = $this->newVariant('INACTIVE-STOCK', false);

        Livewire::test(ListStockQuotas::class)
            ->assertCanSeeTableRecords([$active, $inactive])
            ->filterTable('product_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->filterTable('product_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active])
            ->removeTableFilter('product_active')
            ->assertCanSeeTableRecords([$active, $inactive]);
    }

    public function test_empty_and_low_stock_filters_include_variants_without_quota(): void
    {
        $missing = $this->newVariant('MISSING-STOCK');
        $inactive = $this->newVariant('INACTIVE-EMPTY', false);
        $stock = StockQuota::firstOrFail();
        $stock->update(['allocated' => 100, 'reserved' => 0, 'sold' => 0]);

        Livewire::test(ListStockQuotas::class)
            ->filterTable('product_active', true)
            ->filterTable('empty')
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$stock->variant, $inactive])
            ->removeTableFilter('empty')
            ->filterTable('low')
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$stock->variant, $inactive]);
    }

    public function test_products_without_variants_are_listed_with_activity_filter_and_search(): void
    {
        $active = Product::create(['title' => 'Без вариантов активный', 'slug' => 'without-variants-active', 'is_active' => true]);
        $inactive = Product::create(['title' => 'Без вариантов выключенный', 'slug' => 'without-variants-inactive', 'is_active' => false]);

        Livewire::test(ListStockQuotas::class)
            ->assertSee($active->title)
            ->assertSee($inactive->title)
            ->assertSee('Товары без вариантов')
            ->filterTable('product_active', true)
            ->assertSee($active->title)
            ->assertDontSee($inactive->title)
            ->filterTable('product_active', false)
            ->assertSee($inactive->title)
            ->assertDontSee($active->title)
            ->removeTableFilter('product_active')
            ->searchTable('активный')
            ->assertSee($active->title)
            ->assertDontSee($inactive->title);
    }

    public function test_variant_selector_searches_product_title_and_sku_and_creation_records_initial_allocation(): void
    {
        $variant = $this->newVariant('SELECT-STOCK');
        $component = Livewire::test(CreateStockQuota::class)
            ->assertFormFieldExists('product_variant_id', fn ($field): bool => $field instanceof Select);
        $field = $component->instance()->form->getFlatFields()['product_variant_id'];
        $this->assertArrayHasKey($variant->id, $field->getSearchResults(mb_strtoupper($variant->product->title)));
        $this->assertArrayHasKey($variant->id, $field->getSearchResults('select-stock'));

        $component->fillForm([
            'product_variant_id' => $variant->id, 'allocated' => 12, 'low_threshold' => 3,
            'reserved' => 999, 'sold' => 999,
        ])->call('create')->assertHasNoFormErrors();

        $quota = $variant->fresh()->quota;
        $this->assertSame([12, 0, 0, 3], [$quota->allocated, $quota->reserved, $quota->sold, $quota->low_threshold]);
        $this->assertSame(12, $quota->movements()->sole()->qty);
    }

    public function test_duplicate_and_nonexistent_variants_are_rejected(): void
    {
        foreach ([ProductVariant::firstOrFail()->id, 9999999] as $id) {
            Livewire::test(CreateStockQuota::class)
                ->fillForm(['product_variant_id' => $id, 'allocated' => 10, 'low_threshold' => 5])
                ->call('create')
                ->assertHasFormErrors(['product_variant_id']);
        }
        $this->assertSame(10, StockQuota::count());
    }

    public function test_stock_edit_keeps_variant_and_system_counters_and_logs_adjustment(): void
    {
        $quota = StockQuota::firstOrFail();
        $quota->update(['allocated' => 20, 'reserved' => 3, 'sold' => 4]);
        $variantId = $quota->product_variant_id;

        Livewire::test(EditStockQuota::class, ['record' => $quota->id])
            ->fillForm(['allocated' => 30, 'reserved' => 0, 'sold' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $quota->refresh();
        $this->assertSame([$variantId, 30, 3, 4], [$quota->product_variant_id, $quota->allocated, $quota->reserved, $quota->sold]);
        $this->assertDatabaseHas('stock_quota_movements', ['stock_quota_id' => $quota->id, 'type' => 'adjust', 'qty' => 10]);
    }

    public function test_stock_edit_rejects_a_forged_variant_id(): void
    {
        $quota = StockQuota::firstOrFail();
        $variantId = $quota->product_variant_id;

        Livewire::test(EditStockQuota::class, ['record' => $quota->id])
            ->fillForm(['product_variant_id' => 999999])
            ->call('save')
            ->assertHasFormErrors(['product_variant_id']);

        $this->assertSame($variantId, $quota->fresh()->product_variant_id);
    }

    public function test_stock_cannot_be_lowered_below_new_reservations(): void
    {
        $quota = StockQuota::firstOrFail();
        $component = Livewire::test(EditStockQuota::class, ['record' => $quota->id]);
        $quota->update(['reserved' => 10]);

        $component->fillForm(['allocated' => 5])->call('save')->assertHasFormErrors(['allocated']);

        $this->assertSame(25, $quota->fresh()->allocated);
        $this->assertSame(10, $quota->fresh()->reserved);
    }

    public function test_failed_movement_rolls_back_initial_quota_and_allocation(): void
    {
        $variant = $this->newVariant('ROLLBACK-STOCK');
        StockQuotaMovement::creating(function (): void {
            throw new RuntimeException('Movement failed');
        });

        try {
            Livewire::test(ListStockQuotas::class)
                ->callAction(TestAction::make('replenish')->table($variant), data: ['qty' => 10]);
            $this->fail('Ожидался отказ записи движения.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Movement failed', $exception->getMessage());
        }

        $this->assertNull($variant->fresh()->quota);
    }

    public function test_customer_cannot_access_stock_pages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/admin/stock-quotas')->assertForbidden();
        $this->get('/admin/stock-quotas/create')->assertForbidden();
    }

    public function test_raw_livewire_snapshot_can_filter_products(): void
    {
        config(['app.debug' => false]);
        $html = $this->get('/admin/stock-quotas')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = null;
        foreach ($matches[1] as $value) {
            $candidate = json_decode(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
            if (array_key_exists('tableFilters', $candidate['data'])) {
                $snapshot = $candidate;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        request()->headers->set('X-Livewire', 'true');
        Livewire::flushState();
        [, $effects] = Livewire::update($snapshot, ['tableFilters.product_active.value' => true], []);
        $this->assertStringContainsString('Активность товара', $effects['html']);
        Livewire::flushState();
        [, $effects] = Livewire::update($snapshot, [], [[
            'method' => 'mountAction',
            'params' => ['replenish', [], ['table' => true, 'recordKey' => (string) ProductVariant::firstOrFail()->id]],
        ]]);
        $this->assertStringContainsString('Добавить к выделенному, шт.', json_encode($effects, JSON_UNESCAPED_UNICODE));
    }

    private function newVariant(string $sku, bool $active = true): ProductVariant
    {
        $product = Product::create(['title' => 'Товар '.$sku, 'slug' => strtolower($sku), 'is_active' => $active]);

        return $product->variants()->create(['sku' => $sku, 'price' => 50000, 'option_volume' => '200 мл', 'is_active' => true]);
    }
}
