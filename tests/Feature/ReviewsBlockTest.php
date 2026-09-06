<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\Setting;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Блок отзывов на главной — композиция из макета.
 *
 * Это не список произвольной длины: большая карточка слева на две строки
 * и две узкие справа. Каждая карточка привязана к своему месту (`slot`),
 * поэтому вёрстка не зависит от того, как записи отсортированы в базе.
 */
class ReviewsBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    // ─────────────────────────────────── структура из макета

    public function test_block_has_the_structure_from_the_mockup(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach ([
            'class="reviews',
            'reviews-head',
            'reviews-rating',
            'reviews-grid',
            'review-card review-main',
            'review-card review-side',
            'review-side-copy',
            'review-media',
            'reviews-foot',
        ] as $marker) {
            $this->assertStringContainsString($marker, $html, "В блоке нет «{$marker}»");
        }
    }

    public function test_block_shows_exactly_three_cards(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'review-card review-main'), 'Большая карточка должна быть одна');
        $this->assertSame(2, substr_count($html, 'review-card review-side'), 'Узких карточек должно быть две');
    }

    public function test_cards_stand_in_their_slots(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $main   = Review::where('slot', 'main')->sole();
        $bottom = Review::where('slot', 'bottom')->sole();

        // Большая карточка идёт первой, PRO-карточка — последней.
        $this->assertLessThan(
            strpos($html, $bottom->author),
            strpos($html, $main->author),
            'Порядок карточек не совпадает с макетом',
        );
    }

    /** Порядок в блоке задан местом, а не сортировкой. */
    public function test_reordering_does_not_break_the_layout(): void
    {
        Review::where('slot', 'main')->update(['sort' => 999]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'review-card review-main'));
        $this->assertSame(2, substr_count($html, 'review-card review-side'));
    }

    public function test_card_content_is_rendered(): void
    {
        $main = Review::where('slot', 'main')->sole();

        $this->get('/')
            ->assertOk()
            ->assertSee($main->text, escape: false)
            ->assertSee($main->author)
            ->assertSee($main->caption)
            ->assertSee($main->role_caption);
    }

    public function test_initial_is_taken_from_the_author_name(): void
    {
        Review::where('slot', 'main')->update(['author' => 'Ольга']);

        $this->get('/')->assertOk()->assertSee('<i aria-hidden="true">О</i>', escape: false);
    }

    public function test_stars_match_the_rating(): void
    {
        Review::where('slot', 'top')->update(['rating' => 4]);

        $this->assertSame('★★★★', Review::where('slot', 'top')->sole()->stars());

        $this->get('/')->assertOk()->assertSee('★★★★', escape: false);
    }

    /** Оценка вне 1–5 не должна ломать вывод звёзд. */
    public function test_stars_are_clamped(): void
    {
        $review = Review::where('slot', 'top')->sole();

        $review->rating = 9;
        $this->assertSame('★★★★★', $review->stars());

        $review->rating = 0;
        $this->assertSame('★', $review->stars());
    }

    public function test_caption_falls_back_to_the_product(): void
    {
        $review = Review::where('slot', 'main')->sole();
        $review->update(['caption' => null]);

        $this->assertStringContainsString(
            $review->fresh()->product->title,
            $review->fresh()->captionLine(),
        );
    }

    public function test_pro_card_carries_its_accent(): void
    {
        $this->get('/')->assertOk()->assertSee('review-card review-side pro', escape: false);
    }

    public function test_footer_link_leads_to_the_catalog(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('catalog.index', ['tab' => 'best']), escape: false)
            ->assertSee('Смотреть товары из отзывов');
    }

    // ─────────────────────────────────── шапка блока

    public function test_average_score_comes_from_settings(): void
    {
        Setting::put('reviews_score', '4.7');
        Setting::put('reviews_score_note', 'По отзывам на Ozon и Wildberries');

        $this->get('/')
            ->assertOk()
            ->assertSee('4.7')
            ->assertSee('По отзывам на Ozon и Wildberries');
    }

    /** Без оценки блок с цифрой не выводится — пустая рамка выглядит поломкой. */
    public function test_empty_score_hides_the_rating_block(): void
    {
        Setting::put('reviews_score', '');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('reviews-rating', $html);
        $this->assertStringContainsString('reviews-grid', $html);
    }

    // ─────────────────────────────────── пограничные случаи

    public function test_block_disappears_without_the_main_card(): void
    {
        Review::where('slot', 'main')->update(['is_active' => false]);

        $this->get('/')->assertOk()->assertDontSee('reviews-grid', escape: false);
    }

    public function test_missing_side_card_does_not_break_the_block(): void
    {
        Review::where('slot', 'bottom')->update(['is_active' => false]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'review-card review-main'));
        $this->assertSame(1, substr_count($html, 'review-card review-side'));
    }

    public function test_card_without_a_photo_still_renders(): void
    {
        Review::query()->update(['image_path' => null]);

        $this->get('/')->assertOk()->assertSee('reviews-grid', escape: false);
    }

    /**
     * Разметку рейтинга по-прежнему не отдаём: отзывы собраны на маркетплейсах,
     * а не на сайте, и выдавать их за собственные нельзя.
     */
    public function test_block_has_no_aggregate_rating_markup(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('AggregateRating', $html);
        $this->assertStringNotContainsString('aggregateRating', $html);
    }
}
