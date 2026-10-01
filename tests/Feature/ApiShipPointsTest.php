<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiShipPointsTest extends TestCase
{
    public function test_connected_ozon_points_are_filtered_and_returned_without_secrets(): void
    {
        config([
            'livsi.apiship.token' => 'example-token',
            'livsi.apiship.active_providers' => ['ozonlog'],
        ]);
        Http::fake(['*/lists/points*' => Http::response([
            'rows' => [
                ['id' => '42', 'providerKey' => 'ozonlog', 'countryCode' => 'RU', 'city' => 'Москва', 'availableOperation' => 2, 'enabled' => true, 'name' => 'ПВЗ 42', 'address' => 'Москва, улица 1', 'lat' => 55.7, 'lng' => 37.6],
                ['id' => '43', 'providerKey' => 'ozonlog', 'countryCode' => 'RU', 'city' => 'Москва', 'availableOperation' => 2, 'enabled' => false, 'address' => 'Закрытый ПВЗ'],
                ['id' => '44', 'providerKey' => 'yataxi', 'countryCode' => 'RU', 'city' => 'Москва', 'availableOperation' => 2, 'enabled' => true, 'address' => 'Чужой ПВЗ'],
            ],
            'meta' => ['total' => 3],
        ])]);

        $this->getJson(route('delivery.points', ['method' => 'ozon_point', 'city' => 'Москва']))
            ->assertOk()
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('points.0.id', '42')
            ->assertDontSee('example-token');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'example-token')
            && str_contains($request->url(), 'providerKey%3Dozonlog'));
    }

    public function test_unconnected_provider_never_calls_apiship(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['ozonlog']]);
        Http::fake();

        $this->getJson(route('delivery.points', ['method' => 'yandex_point', 'city' => 'Москва']))
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_provider_failure_uses_safe_message(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['ozonlog']]);
        Http::fake(['*/lists/points*' => Http::response(['message' => 'Internal token leak'], 500)]);

        $this->getJson(route('delivery.points', ['method' => 'ozon_point', 'city' => 'Москва']))
            ->assertStatus(503)
            ->assertDontSee('Internal token leak')
            ->assertJsonPath('message', 'Список ПВЗ временно недоступен. Укажите удобный район или адрес.');
    }

    public function test_point_feed_carries_provider_and_filters_same_named_cities_by_region(): void
    {
        config(['livsi.apiship.token' => 'example-token', 'livsi.apiship.active_providers' => ['ozonlog']]);
        Http::fake(['*/lists/points*' => Http::response(['rows' => [
            ['id' => 42, 'providerKey' => 'ozonlog', 'countryCode' => 'RU', 'city' => 'Александровск',
                'region' => 'Ставропольский', 'availableOperation' => 2, 'enabled' => true,
                'address' => 'Верный адрес', 'lat' => 44.2, 'lng' => 43.4],
            ['id' => 43, 'providerKey' => 'ozonlog', 'countryCode' => 'RU', 'city' => 'Александровск',
                'region' => 'Пермский край', 'availableOperation' => 2, 'enabled' => true,
                'address' => 'Другой город'],
            ['id' => 44, 'providerKey' => 'ozonlog', 'countryCode' => 'RU', 'city' => 'Пятигорск',
                'region' => 'Ставропольский', 'availableOperation' => 2, 'enabled' => true,
                'address' => 'ПВЗ соседнего города'],
        ], 'meta' => ['total' => 3]])]);

        $this->getJson(route('delivery.points', [
            'method' => 'ozon_point', 'city' => 'Александровск', 'region' => 'Ставропольский край',
        ]))->assertOk()->assertJsonCount(2, 'points')
            ->assertJsonPath('points.0.provider', 'ozonlog')
            ->assertJsonPath('points.0.lat', 44.2)
            ->assertJsonPath('points.1.city', 'Пятигорск');

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'region%Ставропольский')
            && ! str_contains(urldecode($request->url()), 'city=Александровск'));
    }
}
