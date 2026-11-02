<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProductImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_renders_public_catalog_image_urls(): void
    {
        config(['filament.default_filesystem_disk' => 'local']);
        Storage::fake('local');
        Storage::fake('public_images', ['url' => 'https://livsi.test']);
        $this->seed(CatalogSeeder::class);
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->images()->delete();
        $product->images()->create(['path' => 'img/catalog/admin-list.png', 'is_primary' => true]);
        Storage::disk('public_images')->put('img/catalog/admin-list.png', 'image');
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/admin/products');

        $response->assertSeeHtml('src="https://livsi.test/img/catalog/admin-list.png"');
    }

    public function test_product_gallery_renders_public_catalog_image_urls(): void
    {
        config(['filament.default_filesystem_disk' => 'local']);
        Storage::fake('local');
        Storage::fake('public_images', ['url' => 'https://livsi.test']);
        $this->seed(CatalogSeeder::class);
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->images()->delete();
        $product->images()->create(['path' => 'img/catalog/admin-gallery.png', 'is_primary' => true]);
        Storage::disk('public_images')->put('img/catalog/admin-gallery.png', 'image');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ImagesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->assertSeeHtml('src="https://livsi.test/img/catalog/admin-gallery.png"');
    }

    public function test_stock_quota_list_shows_first_product_image_and_variant_sku(): void
    {
        config(['filament.default_filesystem_disk' => 'local']);
        Storage::fake('local');
        Storage::fake('public_images', ['url' => 'https://livsi.test']);
        $this->seed(CatalogSeeder::class);
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $product->images()->delete();
        $product->images()->create(['path' => 'img/catalog/quota-first.png', 'sort' => 0, 'is_primary' => false]);
        $product->images()->create(['path' => 'img/catalog/quota-primary.png', 'sort' => 1, 'is_primary' => true]);
        Storage::disk('public_images')->put('img/catalog/quota-first.png', 'image');
        Storage::disk('public_images')->put('img/catalog/quota-primary.png', 'image');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/admin/stock-quotas')
            ->assertOk()
            ->assertSeeHtml('src="https://livsi.test/img/catalog/quota-first.png"')
            ->assertDontSeeHtml('src="https://livsi.test/img/catalog/quota-primary.png"')
            ->assertSee($product->variants()->firstOrFail()->sku);
    }

    public function test_review_list_uses_same_public_image_disk_as_review_upload(): void
    {
        config(['filament.default_filesystem_disk' => 'local']);
        Storage::fake('local');
        Storage::fake('public_images', ['url' => 'https://livsi.test']);
        $this->seed(CatalogSeeder::class);
        Review::where('slot', 'main')->update(['image_path' => 'img/reviews/admin-review.png']);
        Storage::disk('public_images')->put('img/reviews/admin-review.png', 'image');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/admin/reviews')
            ->assertOk()
            ->assertSeeHtml('src="https://livsi.test/img/reviews/admin-review.png"');
    }
}
