<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LegalPagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CitySelectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->seed(LegalPagesSeeder::class);
    }

    public function test_city_is_not_requested_outside_checkout(): void
    {
        foreach (['/', '/catalog', '/cart', '/login', '/partners', '/privacy'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('data-city-open', false)
                ->assertDontSee('data-city-selector', false)
                ->assertDontSee('data-city-search', false);
        }
    }

    public function test_privacy_page_describes_city_detection_only_at_checkout(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('При открытии страницы оформления заказа для предложения города')
            ->assertSee('API Геокодера Яндекса')
            ->assertSee('его можно изменить в форме заказа')
            ->assertDontSee('его можно изменить в шапке сайта');
    }

    public function test_existing_privacy_page_is_updated_without_changing_other_legal_text(): void
    {
        $page = LegalPage::where('slug', 'privacy')->firstOrFail();
        $page->update([
            'body' => '<p>Другие условия обработки данных.</p><p>При первом посещении сайта для предложения города загружается сервис «Яндекс Карты»; его можно изменить в шапке сайта или форме заказа.</p>',
            'reviewed_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_08_152935_scope_city_detection_to_checkout.php');
        $migration->up();

        $page->refresh();
        $this->assertSame('<p>Другие условия обработки данных.</p><p>При открытии страницы оформления заказа для предложения города загружается сервис «Яндекс Карты»; его можно изменить в форме заказа.</p>', $page->body);
        $this->assertNull($page->reviewed_at);
    }

    public function test_coordinates_are_resolved_to_a_russian_city_without_exposing_the_api_key(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response($this->geocoderResponse('RU', 'Ставрополь'))]);

        $this->postJson(route('delivery.city.geocode'), ['latitude' => 45.04, 'longitude' => 41.97])
            ->assertOk()
            ->assertExactJson(['city' => 'Ставрополь', 'region' => 'Ставропольский край'])
            ->assertDontSee('private-geocoder-key');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'geocode=41.97%2C45.04')
            && str_contains($request->url(), 'kind=locality'));
    }

    public function test_foreign_or_missing_geocoder_result_uses_manual_city_selection(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response($this->geocoderResponse('DE', 'Берлин'))]);

        $this->postJson(route('delivery.city.geocode'), ['latitude' => 52.52, 'longitude' => 13.4])
            ->assertNotFound()
            ->assertDontSee('Берлин');
    }

    public function test_selected_settlement_is_centered_with_server_geocoder_and_region_is_verified(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response($this->geocoderResponse('RU', 'Александровское'))]);

        $this->postJson(route('delivery.city.geocode'), [
            'city' => 'Александровское', 'region' => 'Ставропольский край',
        ])->assertOk()->assertExactJson(['latitude' => 45.0445, 'longitude' => 41.9734]);

        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'geocode=Александровское, Ставропольский край, Россия')
            && ! str_contains($request->url(), 'kind=locality'));
    }

    public function test_same_named_settlement_in_another_region_cannot_center_the_map(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response($this->geocoderResponse('RU', 'Александровское'))]);

        $this->postJson(route('delivery.city.geocode'), [
            'city' => 'Александровское', 'region' => 'Томская область',
        ])->assertNotFound();
    }

    public function test_geocoder_result_is_not_stored_in_the_site_cache(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response($this->geocoderResponse('RU', 'Ставрополь'))]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('delivery.city.geocode'), ['latitude' => 45.03, 'longitude' => 41.99])
                ->assertOk();
        }

        Http::assertSentCount(2);
    }

    public function test_geocoder_failure_does_not_break_the_storefront(): void
    {
        config(['livsi.delivery.geocoder_api_key' => 'private-geocoder-key']);
        Http::fake(['geocode-maps.yandex.ru/*' => Http::response([], 503)]);

        $this->postJson(route('delivery.city.geocode'), ['latitude' => 55.75, 'longitude' => 37.61])
            ->assertStatus(503)
            ->assertDontSee('private-geocoder-key');

        $this->get('/')->assertOk();
    }

    private function geocoderResponse(string $country, string $city): array
    {
        return ['response' => ['GeoObjectCollection' => ['featureMember' => [['GeoObject' => [
            'Point' => ['pos' => '41.9734 45.0445'],
            'metaDataProperty' => ['GeocoderMetaData' => [
                'kind' => 'locality',
                'Address' => [
                    'country_code' => $country,
                    'Components' => [
                        ['kind' => 'country', 'name' => $country],
                        ['kind' => 'province', 'name' => 'Северо-Кавказский федеральный округ'],
                        ['kind' => 'province', 'name' => 'Ставропольский край'],
                        ['kind' => 'locality', 'name' => $city],
                    ],
                ],
            ]],
        ]]]]]];
    }
}
