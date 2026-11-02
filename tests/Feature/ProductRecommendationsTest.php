<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Livewire\QuickView;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_existing_products_use_automatic_recommendations_by_default(): void
    {
        $product = Product::firstOrFail();

        $this->assertTrue($product->auto_recommendations);
        $this->assertCount(3, $product->recommendedProducts());
    }

    public function test_automatic_recommendations_prefer_the_same_line_and_exclude_self_and_inactive_products(): void
    {
        Product::query()->update(['is_active' => false]);
        $products = Product::orderBy('id')->get();
        [$current, $sameLine, $otherLine, $inactive] = $products->take(4)->all();
        $current->update(['is_active' => true]);
        $sameLine->update(['is_active' => true, 'product_line_id' => $current->product_line_id, 'sort' => 99]);
        $otherLine->update(['is_active' => true, 'product_line_id' => null, 'sort' => 0]);
        $inactive->update(['product_line_id' => $current->product_line_id, 'sort' => 0]);

        $this->assertSame([$sameLine->id, $otherLine->id], $current->recommendedProducts()->modelKeys());
    }

    public function test_manual_recommendations_keep_selected_order_without_automatic_additions(): void
    {
        [$current, $first, $second] = Product::orderBy('id')->limit(3)->get()->all();
        $current->update(['auto_recommendations' => false, 'recommended_product_ids' => [$second->id, $first->id]]);

        $this->assertSame([$second->id, $first->id], $current->recommendedProducts()->modelKeys());
        $this->get('/product/'.$current->slug)->assertViewHas('related', fn ($related): bool => $related->modelKeys() === [$second->id, $first->id]);
        $component = Livewire::test(QuickView::class)->call('show', $current->id);
        $this->assertSame([$second->id, $first->id], $component->viewData('recommendations')->modelKeys());
    }

    public function test_empty_manual_selection_hides_both_recommendation_blocks(): void
    {
        $product = Product::firstOrFail();
        $product->update(['auto_recommendations' => false, 'recommended_product_ids' => []]);

        $this->get('/product/'.$product->slug)->assertDontSee('С этим покупают');
        Livewire::test(QuickView::class)->call('show', $product->id)->assertDontSee('С этим товаром рекомендуем');
    }

    public function test_deleted_inactive_and_self_recommendations_are_not_rendered(): void
    {
        [$current, $active, $inactive, $deleted] = Product::orderBy('id')->limit(4)->get()->all();
        $current->update(['auto_recommendations' => false, 'recommended_product_ids' => [$current->id, $active->id, $active->id, $inactive->id, $deleted->id]]);
        $inactive->update(['is_active' => false]);
        $deleted->delete();

        $this->assertSame([$active->id], $current->recommendedProducts()->modelKeys());
    }

    public function test_new_product_defaults_to_automatic_recommendations(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(CreateProduct::class)
            ->assertFormSet(['auto_recommendations' => true])
            ->assertFormFieldIsHidden('recommended_product_ids')
            ->fillForm(['title' => 'Тестовая пенка', 'slug' => 'test-recommendations'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Product::where('slug', 'test-recommendations')->firstOrFail()->auto_recommendations);
    }

    public function test_admin_can_choose_manual_recommendations_and_return_to_automatic_without_losing_selection(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $first, $second] = Product::orderBy('id')->limit(3)->get()->all();

        Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false, 'recommended_product_ids' => [$second->id, $first->id]])
            ->assertFormFieldIsVisible('recommended_product_ids')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($current->fresh()->auto_recommendations);
        $this->assertSame([$second->id, $first->id], $current->fresh()->recommended_product_ids);

        Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($current->fresh()->auto_recommendations);
        $this->assertSame([$second->id, $first->id], $current->fresh()->recommended_product_ids);

        Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false])
            ->assertFormSet(['recommended_product_ids' => [$second->id, $first->id]]);
    }

    #[TestWith(['self'])]
    #[TestWith(['inactive'])]
    #[TestWith(['missing'])]
    public function test_admin_cannot_select_an_unavailable_recommendation(string $type): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $other] = Product::orderBy('id')->limit(2)->get()->all();
        $other->update(['is_active' => false]);
        $id = match ($type) {
            'self' => $current->id,
            'inactive' => $other->id,
            'missing' => 999999,
        };

        Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false, 'recommended_product_ids' => [$id]])
            ->call('save')
            ->assertHasErrors(['data.recommended_product_ids.0' => 'in']);

        $this->assertTrue($current->fresh()->auto_recommendations);
        $this->assertNull($current->fresh()->recommended_product_ids);
    }

    public function test_admin_cannot_select_more_than_three_recommendations(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $current = Product::firstOrFail();
        $ids = Product::whereKeyNot($current->id)->limit(4)->pluck('id')->all();

        Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false, 'recommended_product_ids' => $ids])
            ->call('save')
            ->assertHasFormErrors(['recommended_product_ids' => 'max']);

        $this->assertNull($current->fresh()->recommended_product_ids);
    }

    #[TestWith(['тЕсТовый'])]
    #[TestWith(['sEaRcH-'])]
    #[TestWith(['абв-02'])]
    public function test_one_search_field_finds_products_by_title_and_sku_without_case_sensitivity(string $search): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $first] = Product::orderBy('id')->limit(2)->get()->all();
        $first->update(['title' => 'Тестовый уход', 'sku' => 'SEARCH-АБВ-02']);

        $component = Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false])
            ->assertFormFieldDoesNotExist('recommendation_skus');
        $field = $component->instance()->form->getFlatFields(withHidden: true)['recommended_product_ids'];
        $results = $field->getSearchResults($search);

        $this->assertSame([$first->id], array_keys($results));
        $this->assertStringContainsString('SEARCH-АБВ-02', $results[$first->id]);

        $component->fillForm(['recommended_product_ids' => [$first->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$first->id], $current->fresh()->recommended_product_ids);
    }

    public function test_dropdown_uses_the_first_gallery_photo_and_selected_labels_stay_compact(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $other] = Product::orderBy('id')->limit(2)->get()->all();
        $other->update(['sku' => 'PHOTO-001']);
        $other->images()->delete();
        $other->images()->create(['path' => 'img/catalog/second.png', 'sort' => 2, 'is_primary' => true]);
        $other->images()->create(['path' => 'img/catalog/first.png', 'sort' => 1, 'is_primary' => false]);

        $component = Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false, 'recommended_product_ids' => [$other->id]]);
        $field = $component->instance()->form->getFlatFields(withHidden: true)['recommended_product_ids'];
        $option = $field->getSearchResults('PHOTO-001')[$other->id];

        $this->assertStringContainsString('src="'.asset('img/catalog/first.png').'"', $option);
        $this->assertStringNotContainsString('second.png', $option);
        $this->assertSame([$other->id => $other->title.' · PHOTO-001'], $field->getOptionLabels());
    }

    public function test_search_does_not_offer_current_inactive_or_unmatched_products(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $inactive] = Product::orderBy('id')->limit(2)->get()->all();
        $current->update(['sku' => 'EXCLUDED-SELF']);
        $inactive->update(['sku' => 'EXCLUDED-INACTIVE', 'is_active' => false]);

        $component = Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false]);
        $field = $component->instance()->form->getFlatFields(withHidden: true)['recommended_product_ids'];

        $this->assertSame([], $field->getSearchResults('EXCLUDED-'));
        $this->assertSame([], $field->getSearchResults('UNKNOWN-001'));
    }

    public function test_products_without_photos_do_not_render_broken_images(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $other] = Product::orderBy('id')->limit(2)->get()->all();
        $other->images()->delete();

        $component = Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false]);
        $field = $component->instance()->form->getFlatFields(withHidden: true)['recommended_product_ids'];

        $this->assertStringNotContainsString('<img', $field->getOptions()[$other->id]);
    }

    public function test_html_option_labels_escape_product_title_and_sku(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$current, $other] = Product::orderBy('id')->limit(2)->get()->all();
        $other->update(['title' => '<script>alert("title")</script>', 'sku' => '<img src=x onerror=alert(1)>']);

        $component = Livewire::test(EditProduct::class, ['record' => $current->getRouteKey()])
            ->fillForm(['auto_recommendations' => false, 'recommended_product_ids' => [$other->id]]);
        $field = $component->instance()->form->getFlatFields(withHidden: true)['recommended_product_ids'];
        $option = $field->getOptions()[$other->id];

        $this->assertStringContainsString(e($other->title), $option);
        $this->assertStringContainsString(e($other->sku), $option);
        $this->assertStringNotContainsString($other->title, $option);
        $this->assertStringNotContainsString($other->sku, $option);
        $this->assertSame([$other->id => e($other->title.' · '.$other->sku)], $field->getOptionLabels());
    }

    public function test_customer_cannot_edit_recommendations(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $current = Product::firstOrFail();

        $this->get('/admin/products/'.$current->getRouteKey().'/edit')->assertForbidden();

        $this->assertTrue($current->fresh()->auto_recommendations);
    }
}
