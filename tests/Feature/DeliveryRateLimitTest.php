<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'livsi.apiship.token' => 'example-token',
            'livsi.apiship.active_providers' => ['cdek'],
            'livsi.delivery.geocoder_api_key' => 'private-geocoder-key',
        ]);
    }

    public function test_point_limit_is_enforced_without_exhausting_the_geocoder(): void
    {
        $this->fakeDeliveryApis();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson(route('delivery.points', ['method' => 'cdek_point', 'city' => 'Ставрополь']))->assertOk();
        }
        $this->getJson(route('delivery.points', ['method' => 'cdek_point', 'city' => 'Ставрополь']))
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson(route('delivery.city.geocode'), ['latitude' => 45.03, 'longitude' => 41.99])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_geocoder_limit_is_enforced_without_blocking_points(): void
    {
        $this->fakeDeliveryApis();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson(route('delivery.city.geocode'), ['latitude' => 45.03, 'longitude' => 41.99])->assertOk();
        }
        $this->postJson(route('delivery.city.geocode'), ['latitude' => 45.03, 'longitude' => 41.99])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->getJson(route('delivery.points', ['method' => 'cdek_point', 'city' => 'Ставрополь']))->assertOk();

        Http::assertSentCount(11);
    }

    private function fakeDeliveryApis(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/lists/points*' => Http::response(['rows' => [], 'meta' => ['total' => 0]]),
            'geocode-maps.yandex.ru/*' => Http::response([
                'response' => ['GeoObjectCollection' => ['featureMember' => [['GeoObject' => [
                    'metaDataProperty' => ['GeocoderMetaData' => [
                        'kind' => 'locality',
                        'Address' => ['country_code' => 'RU', 'Components' => [
                            ['kind' => 'locality', 'name' => 'Ставрополь'],
                        ]],
                    ]],
                ]]]]],
            ]),
        ]);
    }
}
