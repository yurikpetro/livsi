<?php

namespace Tests\Feature;

use App\Filament\Resources\UgcItems\Pages\CreateUgcItem;
use App\Filament\Resources\UgcItems\Pages\EditUgcItem;
use App\Livewire\QuickView;
use App\Models\Product;
use App\Models\UgcItem;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_opens_in_a_vertical_dialog_instead_of_playing_in_the_card(): void
    {
        $item = UgcItem::create(['image_path' => 'img/ugc/cover.png', 'media_paths' => ['media/ugc/first.mp4', 'media/ugc/next.png'], 'alt' => 'Просмотр', 'is_active' => true]);

        $html = $this->get('/')->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//article[@data-ugc-card]/button[@data-ugc-open and @aria-controls="ugc-viewer-'.$item->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="ugc-viewer-'.$item->id.'"]//video/source[@src="'.asset('media/ugc/first.mp4').'"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="ugc-viewer-'.$item->id.'"]//button[@data-ugc-next]')->length);
        $this->assertSame(0, $xpath->query('//article[@data-ugc-card]/div//video')->length);
    }

    public function test_failed_file_write_does_not_save_a_broken_video_reference(): void
    {
        $storage = Storage::fake('public_images');
        $storage->put('img/ugc/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMv8AAAAASUVORK5CYII='));
        $disk = \Mockery::mock($storage)->makePartial();
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::set('public_images', $disk);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(CreateUgcItem::class)->fillForm([
            'image_path' => ['cover' => 'img/ugc/cover.png'],
            'video_path' => UploadedFile::fake()->create('missing.mp4', 100, 'video/mp4'),
            'alt' => 'Ошибка записи',
        ])->call('create')->assertHasFormErrors(['video_path'])
            ->assertSee('Не удалось сохранить файл.');

        $this->assertDatabaseCount('ugc_items', 0);
    }

    public function test_admin_can_upload_mixed_media_and_publish_it_in_order(): void
    {
        Storage::fake('public_images');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMv8AAAAASUVORK5CYII=');

        Livewire::test(CreateUgcItem::class)->fillForm([
            'image_path' => UploadedFile::fake()->createWithContent('cover.png', $png),
            'media_paths' => [
                UploadedFile::fake()->createWithContent('first.png', $png),
                UploadedFile::fake()->create('second.mp4', 1024, 'video/mp4'),
                UploadedFile::fake()->createWithContent('third.png', $png),
            ],
            'alt' => 'Три кадра', 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();

        $item = UgcItem::where('alt', 'Три кадра')->firstOrFail();
        $this->assertCount(3, $item->media_paths);
        Storage::disk('public_images')->assertExists($item->media_paths);
        $this->assertSame(['image', 'video', 'image'], array_column($item->galleryMedia(), 'type'));
        $this->get('/')->assertSeeInOrder(array_map(fn (string $path): string => asset($path), $item->media_paths), false)
            ->assertSee('Кадр 3 из 3')->assertSee('Следующий кадр: Три кадра');
    }

    public function test_admin_can_reorder_and_remove_gallery_frames_without_losing_the_cover(): void
    {
        Storage::fake('public_images');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['img/ugc/cover.png', 'media/ugc/first.png', 'media/ugc/second.webm', 'media/ugc/third.png'] as $path) {
            Storage::disk('public_images')->put($path, 'file');
        }
        $item = UgcItem::create(['image_path' => 'img/ugc/cover.png', 'media_paths' => ['media/ugc/first.png', 'media/ugc/second.webm', 'media/ugc/third.png'], 'alt' => 'Порядок', 'is_active' => true]);

        Livewire::test(EditUgcItem::class, ['record' => $item->getRouteKey()])
            ->fillForm(['media_paths' => ['media/ugc/third.png', 'media/ugc/second.webm']])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['media/ugc/third.png', 'media/ugc/second.webm'], $item->fresh()->media_paths);
        $this->assertSame('img/ugc/cover.png', $item->fresh()->image_path);
        $this->get('/')->assertSeeInOrder(['media/ugc/third.png', 'media/ugc/second.webm'], false)->assertDontSee('media/ugc/first.png', false);

        Livewire::test(EditUgcItem::class, ['record' => $item->getRouteKey()])
            ->fillForm(['media_paths' => []])->call('save')->assertHasNoFormErrors();

        $this->assertSame([], $item->fresh()->media_paths);
        $this->get('/')->assertSee('img/ugc/cover.png', false)->assertDontSee('media/ugc/second.webm', false);
    }

    #[DataProvider('invalidVideos')]
    public function test_gallery_rejects_unsafe_or_oversized_uploads(string $name, int $size, string $mime): void
    {
        Storage::fake('public_images');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(CreateUgcItem::class)->fillForm(['media_paths' => [UploadedFile::fake()->create($name, $size, $mime)]])
            ->call('create')->assertHasFormErrors(['media_paths']);

        $this->assertDatabaseCount('ugc_items', 0);
    }

    public function test_empty_gallery_preserves_existing_video_and_photo_cards(): void
    {
        UgcItem::create(['image_path' => 'img/ugc/legacy-photo.png', 'alt' => 'Фото', 'is_active' => true]);
        UgcItem::create(['image_path' => 'img/ugc/legacy-cover.png', 'video_path' => 'media/ugc/legacy.mp4', 'media_paths' => [], 'alt' => 'Видео', 'is_active' => true]);

        $this->get('/')->assertSee('img/ugc/legacy-photo.png', false)->assertSee('media/ugc/legacy.mp4', false)
            ->assertSee('Следующий кадр: Видео')->assertDontSee('Следующий кадр: Фото');
    }

    public function test_home_shelf_identifies_care_and_professional_lines(): void
    {
        $this->seed(CatalogSeeder::class);
        Product::where('slug', 'parfyumirovannyy-mist-dlya-tela')->update(['badge' => null]);

        $html = $this->get('/')->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($document);

        foreach ([
            'fresh' => 'Мультипенка для рук и стоп',
            'sweet' => 'Парфюмированный мист для тела',
            'warm' => 'Скраб для тела',
            'base' => 'Крем для рук и стоп 25% Urea',
            'pro' => 'Кератолитический гель для стоп',
        ] as $line => $title) {
            $this->assertSame(1, $xpath->query('//article[@data-shelf-line="'.$line.'"]//button[@aria-label="Быстрый просмотр: '.$title.'"]')->length, $line);
        }
    }

    public function test_home_shelf_includes_products_beyond_the_first_24_and_excludes_inactive_products(): void
    {
        $this->seed(CatalogSeeder::class);
        $original = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        for ($index = 1; $index <= 24; $index++) {
            $product = $original->replicate();
            $product->fill(['slug' => 'extra-warm-'.$index, 'title' => 'Дополнительный WARM '.$index, 'sort' => 100 + $index])->save();
        }
        $original->update(['is_active' => false, 'title' => 'Скрытый WARM']);

        $this->get('/')->assertSee('Быстрый просмотр: Дополнительный WARM 24')->assertDontSee('Быстрый просмотр: Скрытый WARM');
    }

    public function test_admin_can_upload_a_reel_with_cover_and_product(): void
    {
        $this->seed(CatalogSeeder::class);
        Storage::fake('public_images');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $product = Product::where('slug', 'skrab-dlya-tela')->firstOrFail();
        $cover = UploadedFile::fake()->createWithContent('cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMv8AAAAASUVORK5CYII='));
        $video = UploadedFile::fake()->create('reel.mp4', 1024, 'video/mp4');

        Livewire::test(CreateUgcItem::class)->fillForm([
            'image_path' => $cover, 'video_path' => $video, 'alt' => 'Новый ролик',
            'product_id' => $product->id, 'sort' => 60, 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();

        $item = UgcItem::where('alt', 'Новый ролик')->firstOrFail();
        $this->assertSame($product->id, $item->product_id);
        Storage::disk('public_images')->assertExists([$item->video_path, $item->image_path]);
        $this->get('/')->assertSee(asset($item->video_path), false)->assertSee('Смотреть ролик: Новый ролик')->assertSee($product->title);
    }

    public function test_admin_can_remove_video_without_losing_the_cover(): void
    {
        Storage::fake('public_images');
        Storage::disk('public_images')->put('img/ugc/cover.jpg', 'cover');
        Storage::disk('public_images')->put('media/ugc/reel.mp4', 'video');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $item = UgcItem::create(['image_path' => 'img/ugc/cover.jpg', 'video_path' => 'media/ugc/reel.mp4', 'alt' => 'Фото', 'sort' => 0, 'is_active' => true]);

        Livewire::test(EditUgcItem::class, ['record' => $item->getRouteKey()])->fillForm(['video_path' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($item->fresh()->video_path);
        $this->assertSame('img/ugc/cover.jpg', $item->fresh()->image_path);
        $this->get('/')->assertDontSee('Смотреть ролик: Фото')->assertSee('img/ugc/cover.jpg', false);
    }

    #[DataProvider('invalidVideos')]
    public function test_admin_rejects_invalid_video(string $name, int $size, string $mime): void
    {
        Storage::fake('public_images');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(CreateUgcItem::class)->fillForm(['video_path' => UploadedFile::fake()->create($name, $size, $mime)])
            ->call('create')->assertHasFormErrors(['video_path']);

        $this->assertDatabaseCount('ugc_items', 0);
    }

    public static function invalidVideos(): array
    {
        return [['script.php', 1, 'text/plain'], ['large.mp4', 30721, 'video/mp4']];
    }

    public function test_reels_admin_is_closed_to_customers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin/ugc-items/create')->assertForbidden();
    }

    public function test_hidden_reels_and_inactive_product_links_are_not_published(): void
    {
        $this->seed(CatalogSeeder::class);
        UgcItem::query()->update(['is_active' => false]);
        $product = Product::firstOrFail();
        $product->update(['is_active' => false]);
        UgcItem::create(['image_path' => 'img/catalog/livsi-fresh.png', 'video_path' => 'media/ugc/visible.webm', 'alt' => '<script>bad()</script>', 'product_id' => $product->id, 'sort' => 0, 'is_active' => true]);
        UgcItem::create(['image_path' => 'img/catalog/livsi-fresh.png', 'video_path' => 'media/ugc/hidden.mp4', 'sort' => 1, 'is_active' => false]);

        $this->get('/')->assertSee('media/ugc/visible.webm', false)->assertDontSee('media/ugc/hidden.mp4', false)
            ->assertDontSee(route('catalog.show', $product), false)->assertDontSee('<script>bad()</script>', false)->assertSee('&lt;script&gt;bad()', false);
    }

    public function test_quick_view_uses_product_details_and_only_active_recommendations(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $product->update(['composition' => 'Подтверждённый состав', 'application' => 'Подтверждённое применение']);
        Product::where('slug', 'krem-dlya-ruk-i-stop-25-urea')->update(['is_active' => false, 'title' => 'Скрытый товар']);

        Livewire::test(QuickView::class)->dispatch('quick-view', productId: $product->id)
            ->assertSee('Подтверждённый состав')->assertSee('Подтверждённое применение')
            ->assertSee('С этим товаром рекомендуем')->assertDontSee('Скрытый товар');
    }

    public function test_photo_migration_preserves_custom_images(): void
    {
        $this->seed(CatalogSeeder::class);
        $product = Product::where('slug', 'multipenka-dlya-ruk-i-stop')->firstOrFail();
        $product->primaryImage()->update(['path' => 'img/custom/my-photo.jpg']);
        $cream = Product::where('slug', 'krem-dlya-ruk-i-stop-25-urea')->firstOrFail();
        $cream->primaryImage()->update(['path' => 'img/catalog/warm-portrait.jpg']);

        $migration = require database_path('migrations/2026_10_08_173211_align_prototype_product_images.php');
        $migration->up();

        $this->assertSame('img/custom/my-photo.jpg', $product->fresh()->primaryImage()->path);
        $this->assertSame('img/catalog/livsi-base.png', $cream->fresh()->primaryImage()->path);
        $this->assertDatabaseHas('product_images', [
            'product_id' => Product::where('slug', 'skrab-dlya-tela')->value('id'),
            'path' => 'img/catalog/mango-product.jpg',
            'is_primary' => false,
        ]);
    }
}
