<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AdminProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_product_is_created_with_multiple_variants_and_prices_in_kopecks(): void
    {
        Livewire::test(CreateProduct::class)
            ->callAction(TestAction::make('add')->schemaComponent('variants', schema: 'form'))
            ->assertFormSet(fn (array $state): bool => count($state['variants']) === 1)
            ->fillForm([
                'title' => 'Новый товар с вариантами',
                'slug' => 'new-product-with-variants',
                'variants' => [
                    $this->variantData('INLINE-200', ['is_default' => true]),
                    $this->variantData('INLINE-500', [
                        'option_volume' => '500 мл',
                        'volume_ml' => 500,
                        'price' => '1 990,50',
                        'compare_at_price' => '2499',
                    ]),
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'new-product-with-variants')->firstOrFail();
        $this->assertCount(2, $product->variants);
        $small = $product->variants->firstWhere('sku', 'INLINE-200');
        $large = $product->variants->firstWhere('sku', 'INLINE-500');
        $this->assertSame(99000, $small->price);
        $this->assertNull($small->compare_at_price);
        $this->assertTrue($small->is_default);
        $this->assertSame(199050, $large->price);
        $this->assertSame(249900, $large->compare_at_price);
        $this->assertSame(500, $large->volume_ml);
        $this->assertSame('вишня', $large->option_aroma);
        $this->assertSame('123456789', $large->barcode);
        $this->assertTrue($large->is_active);
    }

    public function test_existing_variants_and_stock_are_preserved_when_only_product_changes(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $variants = $product->variants()->get()->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->id => $variant->only(['sku', 'price', 'compare_at_price', 'sort', 'is_default', 'is_active']),
        ])->all();
        $quotas = $product->variants->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->id => $variant->quota?->getAttributes(),
        ])->all();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['title' => 'Новое название'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($variants, $product->variants()->get()->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->id => $variant->only(['sku', 'price', 'compare_at_price', 'sort', 'is_default', 'is_active']),
        ])->all());
        $this->assertSame($quotas, $product->fresh()->variants->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->id => $variant->quota?->getAttributes(),
        ])->all());
    }

    public function test_variant_is_edited_inside_product_without_replacing_its_id_or_stock(): void
    {
        $variant = ProductVariant::firstOrFail();
        $quota = $variant->quota?->getAttributes();

        Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()])
            ->fillForm([
                "variants.record-{$variant->id}.price" => '1 249,50',
                "variants.record-{$variant->id}.compare_at_price" => '1500',
                "variants.record-{$variant->id}.option_aroma" => 'Новый аромат',
                "variants.record-{$variant->id}.weight_g" => 250,
                "variants.record-{$variant->id}.length_mm" => 80,
                "variants.record-{$variant->id}.width_mm" => 60,
                "variants.record-{$variant->id}.height_mm" => 140,
                "variants.record-{$variant->id}.dimensions_verified" => true,
                "variants.record-{$variant->id}.is_active" => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(124950, $variant->price);
        $this->assertSame(150000, $variant->compare_at_price);
        $this->assertSame('Новый аромат', $variant->option_aroma);
        $this->assertSame([250, 80, 60, 140], [$variant->weight_g, $variant->length_mm, $variant->width_mm, $variant->height_mm]);
        $this->assertTrue($variant->dimensions_verified);
        $this->assertFalse($variant->is_active);
        $this->assertSame($quota, $variant->quota?->getAttributes());
    }

    public function test_variants_can_be_added_and_removed_when_editing_product(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $oldIds = $product->variants()->pluck('id')->all();
        $other = ProductVariant::where('product_id', '!=', $product->id)->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => [$this->variantData('INLINE-NEW')]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['INLINE-NEW'], $product->variants()->pluck('sku')->all());
        $this->assertSame(0, ProductVariant::whereKey($oldIds)->count());
        $this->assertModelExists($other);
    }

    public function test_variants_are_not_written_before_product_is_saved(): void
    {
        $count = ProductVariant::count();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Несохранённый товар',
                'slug' => 'unsaved-inline-product',
                'variants' => [$this->variantData('INLINE-UNSAVED')],
            ]);

        $this->assertSame($count, ProductVariant::count());
        $this->assertDatabaseMissing('products', ['slug' => 'unsaved-inline-product']);
    }

    public function test_duplicate_variant_skus_in_one_form_are_rejected_without_creating_product(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Товар с дублем',
                'slug' => 'duplicate-inline-skus',
                'variants' => [$this->variantData('INLINE-DUPLICATE'), $this->variantData('INLINE-DUPLICATE')],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants.0.sku', 'variants.1.sku']);

        $this->assertDatabaseMissing('products', ['slug' => 'duplicate-inline-skus']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'INLINE-DUPLICATE']);
    }

    public function test_sku_from_another_product_is_rejected(): void
    {
        $variant = ProductVariant::firstOrFail();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Товар с занятым артикулом',
                'slug' => 'existing-inline-sku',
                'variants' => [$this->variantData($variant->sku)],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants.0.sku' => 'unique']);

        $this->assertDatabaseMissing('products', ['slug' => 'existing-inline-sku']);
    }

    public function test_invalid_variant_price_prevents_product_and_variant_changes(): void
    {
        $variant = ProductVariant::firstOrFail();
        $title = $variant->product->title;
        $price = $variant->price;

        Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()])
            ->fillForm([
                'title' => 'Не должно сохраниться',
                "variants.record-{$variant->id}.price" => '-10',
            ])
            ->call('save')
            ->assertHasFormErrors(["variants.record-{$variant->id}.price"]);

        $this->assertSame($title, $variant->product->fresh()->title);
        $this->assertSame($price, $variant->fresh()->price);
    }

    public function test_variant_navigation_and_separate_relation_tab_are_removed(): void
    {
        $this->assertFalse(ProductVariantResource::shouldRegisterNavigation());
        $this->assertNotContains(VariantsRelationManager::class, ProductResource::getRelations());
    }

    public function test_failed_variant_creation_rolls_back_new_product(): void
    {
        ProductVariant::creating(function (): void {
            throw new RuntimeException('Variant save failed');
        });

        $component = Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Товар для проверки отката',
                'slug' => 'inline-rollback-create',
                'variants' => [$this->variantData('INLINE-ROLLBACK')],
            ]);

        try {
            $component->call('create');
            $this->fail('Ожидалась ошибка сохранения варианта.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Variant save failed', $exception->getMessage());
        }

        $this->assertDatabaseMissing('products', ['slug' => 'inline-rollback-create']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'INLINE-ROLLBACK']);
    }

    public function test_failed_variant_save_rolls_back_product_changes_and_variant_deletions(): void
    {
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $title = $product->title;
        $oldIds = $product->variants()->pluck('id')->all();
        ProductVariant::creating(function (): void {
            throw new RuntimeException('Variant save failed');
        });

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'title' => 'Не должно сохраниться',
                'variants' => [$this->variantData('INLINE-ROLLBACK')],
            ]);

        try {
            $component->call('save');
            $this->fail('Ожидалась ошибка сохранения варианта.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Variant save failed', $exception->getMessage());
        }

        $this->assertSame($title, $product->fresh()->title);
        $this->assertSame($oldIds, $product->variants()->pluck('id')->all());
        $this->assertDatabaseMissing('product_variants', ['sku' => 'INLINE-ROLLBACK']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function variantData(string $sku, array $overrides = []): array
    {
        return array_replace([
            'sku' => $sku,
            'barcode' => '123456789',
            'option_volume' => '200 мл',
            'option_aroma' => 'вишня',
            'volume_ml' => 200,
            'price' => '990',
            'compare_at_price' => null,
            'is_default' => false,
            'is_active' => true,
            'dimensions_verified' => false,
            'sort' => 0,
        ], $overrides);
    }
}
