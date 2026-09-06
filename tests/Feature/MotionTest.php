<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Зацепки для эффектов витрины.
 *
 * Сами анимации живут в CSS и resources/js/app.js, но держатся они
 * на атрибутах в разметке. Если атрибут пропадёт при правке шаблона,
 * эффект тихо перестанет работать и никто этого не заметит —
 * поэтому проверяем именно зацепки.
 */
class MotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_page_has_the_reading_progress_bar(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-scroll-progress', escape: false)
            ->assertSee('class="scroll-progress"', escape: false);
    }

    public function test_hero_carries_the_parallax_hooks(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-hero', $html, 'Первый экран не подписан для параллакса');
        $this->assertStringContainsString('hero-photo', $html);
        $this->assertStringContainsString('hero-copy', $html);
    }

    public function test_sections_are_marked_for_reveal(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Первый экран не размечаем: у него своя анимация появления.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'data-reveal'));
    }

    /** Шаг выезда карточек задаётся переменной на самой карточке. */
    public function test_product_cards_carry_their_stagger_index(): void
    {
        foreach (['/', '/catalog'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('reveal-stagger', $html, "Нет сетки с выездом на {$url}");
            $this->assertStringContainsString('--index: 0', $html);
            $this->assertStringContainsString('--index: 1', $html);
        }
    }

    // ─────────────────────────────────── вопросы и ответы

    /**
     * Остаётся <details>: без JavaScript ответы раскрываются мгновенно,
     * но раскрываются. Скрипт только добавляет плавность.
     */
    public function test_faq_stays_usable_without_javascript(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString('data-faq', $html);
        $this->assertStringContainsString('data-faq-answer', $html);
    }

    public function test_faq_answers_are_in_the_markup(): void
    {
        $answer = \App\Models\FaqItem::where('is_active', true)->orderBy('sort')->first();

        $this->get('/')->assertOk()->assertSee($answer->answer, escape: false);
    }

    /** Скрипт ищет ответ по атрибуту, а не по вёрстке вокруг. */
    public function test_every_question_has_an_answer_hook(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $questions = substr_count($html, '<summary');
        $answers   = substr_count($html, 'data-faq-answer');

        $this->assertSame($questions, $answers, 'У части вопросов нет размеченного ответа');
        $this->assertGreaterThan(0, $questions);
    }

    // ─────────────────────────────────── изображение первого экрана

    /** Фотография первого экрана грузится сразу и в приоритете. */
    public function test_hero_photo_is_not_lazy(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $position = strpos($html, 'hero-photo');
        // Окно большое: в <picture> три длинных srcset до самого <img>.
        $chunk    = substr($html, $position, 3000);

        $this->assertStringContainsString('loading="eager"', $chunk);
        $this->assertStringContainsString('fetchpriority="high"', $chunk);
    }

    /**
     * Для первого экрана должен собираться вариант по ширине исходника.
     *
     * Раньше конвейер отбрасывал ступень выше исходника целиком, и картинка
     * шириной 1464 отдавалась вариантом на 1200 — то есть растягивалась.
     */
    public function test_hero_photo_has_a_full_width_derivative(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('img/derived/manifest.json')),
            true,
        );

        $entry = $manifest['img/catalog/fresh-hero.jpg'] ?? null;

        $this->assertNotNull($entry, 'Фотографии первого экрана нет в манифесте');

        $widest = max(array_column($entry['webp'], 'width'));

        $this->assertSame($entry['width'], $widest, 'Самый широкий вариант уже исходника');
    }
}
