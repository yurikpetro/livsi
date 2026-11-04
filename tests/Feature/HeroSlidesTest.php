<?php

namespace Tests\Feature;

use App\Filament\Resources\HeroSlides\Pages\EditHeroSlide;
use App\Filament\Resources\HeroSlides\Pages\ListHeroSlides;
use App\Models\HeroSlide;
use App\Models\User;
use App\Support\ImageDerivatives;
use App\Support\Media;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Баннеры первого экрана главной: правятся в админке, на телефоне — своя
 * картинка или обрезка вокруг главной точки.
 */
class HeroSlidesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Media::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            File::delete(public_path($path));
        }
        foreach (HeroSlide::query()->get() as $slide) {
            foreach ([$slide->desktop_image, $slide->mobile_image] as $path) {
                if ($path && str_starts_with($path, 'img/banners/')) {
                    File::delete(public_path($path));
                    ImageDerivatives::forget($path);
                    File::delete(glob(public_path('img/derived/banners/'.pathinfo($path, PATHINFO_FILENAME).'-*')) ?: []);
                }
            }
        }
        Media::flush();
        parent::tearDown();
    }

    public function test_current_five_banners_are_moved_to_the_editor(): void
    {
        $this->assertSame(['fresh', 'sweet', 'warm', 'base', 'pro'], HeroSlide::query()->shown()->pluck('code')->all());
        $this->assertSame('img/catalog/livsi-base.png', HeroSlide::where('code', 'base')->value('desktop_image'));
    }

    public function test_home_shows_edited_texts_focus_and_phone_picture(): void
    {
        $this->image('img/banners/test-phone.jpg', 1080, 1920);
        HeroSlide::where('code', 'fresh')->update([
            'title_first' => 'Новая', 'title_accent' => 'строка.', 'desktop_focus_x' => 72, 'desktop_focus_y' => 40,
            'mobile_image' => 'img/banners/test-phone.jpg', 'mobile_focus_x' => 30, 'mobile_focus_y' => 20,
        ]);
        HeroSlide::where('code', 'warm')->update(['is_active' => false]);

        $this->get('/')->assertOk()
            ->assertSee('Новая')
            ->assertSeeHtml('--focus: 72% 40%; --focus-m: 30% 20%')
            ->assertSeeHtml('<source media="(max-width: 767px)" srcset="'.asset('img/banners/test-phone.jpg').'"')
            ->assertDontSeeHtml('data-hero-slide="warm"')
            ->assertDontSeeHtml('data-hero-select="warm"');
    }

    public function test_phone_follows_desktop_point_without_its_own(): void
    {
        $slide = HeroSlide::where('code', 'base')->firstOrFail();
        $this->assertSame('56% 50%', $slide->mobileFocus(), 'прежняя точка телефона из стилей переносится');
        $slide->update(['desktop_focus_x' => 80, 'desktop_focus_y' => 30, 'mobile_focus_x' => null, 'mobile_focus_y' => null]);

        $this->assertSame('80% 30%', $slide->fresh()->mobileFocus());
        $slide->update(['mobile_image' => 'img/banners/x.jpg']);
        $this->assertSame('50% 50%', $slide->fresh()->mobileFocus());
    }

    public function test_editor_shows_preview_and_size_advice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $slide = HeroSlide::where('code', 'base')->firstOrFail();
        $this->image('img/banners/small.jpg', 1200, 800);
        $slide->update(['desktop_image' => 'img/banners/small.jpg']);

        Livewire::test(ListHeroSlides::class)->assertOk()->assertCanSeeTableRecords(HeroSlide::all());
        Livewire::test(EditHeroSlide::class, ['record' => $slide->getRouteKey()])
            ->assertOk()
            ->assertSee('Как будет на сайте')
            ->assertSee('Маловато: 1200×800')
            ->assertSee('Своей картинки для телефона нет');
    }

    public function test_uploaded_banner_is_saved_with_light_copies_and_points(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $slide = HeroSlide::where('code', 'base')->firstOrFail();

        Livewire::test(EditHeroSlide::class, ['record' => $slide->getRouteKey()])
            // В браузере одиночное поле сначала убирает прежний файл, потом кладёт новый.
            ->set('data.desktop_image', [])
            ->fillForm([
                'desktop_image' => UploadedFile::fake()->image('banner.jpg', 2560, 1440),
                'mobile_image' => UploadedFile::fake()->image('phone.jpg', 1080, 1920),
                'desktop_focus_x' => 70, 'desktop_focus_y' => 35, 'mobile_focus_x' => 40, 'mobile_focus_y' => 25,
            ])
            ->assertSee('Хорошо: 2560×1440')
            ->call('save')
            ->assertHasNoFormErrors();

        $slide->refresh();
        $this->assertStringStartsWith('img/banners/', $slide->desktop_image);
        $this->assertFileExists(public_path($slide->desktop_image));
        $this->assertSame([70, 35, 40, 25], [$slide->desktop_focus_x, $slide->desktop_focus_y, $slide->mobile_focus_x, $slide->mobile_focus_y]);

        Media::flush();
        $this->assertTrue(Media::has($slide->desktop_image));
        $this->assertSame(['width' => 2560, 'height' => 1440], Media::dimensions($slide->desktop_image));
        $this->assertStringContainsString('-1600.webp 1600w', (string) Media::srcset($slide->desktop_image, 'webp'));
        $this->assertTrue(Media::has($slide->mobile_image));
    }

    /** Исходника на сервере может не быть (только лёгкие копии) — сохранение текстов картинку не теряет. */
    public function test_saving_texts_keeps_the_picture_even_without_its_original_file(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $slide = HeroSlide::where('code', 'base')->firstOrFail();
        $slide->update(['desktop_image' => 'img/catalog/livsi-banner-without-original.png']);
        $this->assertFileDoesNotExist(public_path($slide->desktop_image));

        Livewire::test(EditHeroSlide::class, ['record' => $slide->getRouteKey()])
            ->fillForm(['title_first' => 'Основа,'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Основа,', 'img/catalog/livsi-banner-without-original.png'], [$slide->fresh()->title_first, $slide->fresh()->desktop_image]);
    }

    public function test_bad_color_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $slide = HeroSlide::where('code', 'base')->firstOrFail();

        Livewire::test(EditHeroSlide::class, ['record' => $slide->getRouteKey()])
            ->fillForm(['accent_color' => 'red;background:url(x)'])
            ->call('save')
            ->assertHasFormErrors(['accent_color']);
    }

    private function image(string $path, int $width, int $height): void
    {
        File::ensureDirectoryExists(dirname(public_path($path)));
        imagejpeg(imagecreatetruecolor($width, $height), public_path($path));
        $this->made[] = $path;
    }
}
