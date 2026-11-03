<?php

namespace Tests\Feature;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Filament\Pages\DeliveryIntegrations;
use App\Models\DeliveryPoint;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Скрытые пункты выдачи и поиск пунктов с несуразной ценой. */
class HiddenPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['livsi.yandex_delivery.test' => true, 'livsi.yandex_delivery.test_token' => 'yandex-test-token',
            'livsi.delivery.drivers.yataxi' => 'direct']);
        Setting::put('delivery_direct_origin', ['yataxi' => 'station-stv']);
    }

    private function point(string $id, string $address): DeliveryPoint
    {
        return DeliveryPoint::create(['provider' => 'yataxi', 'external_id' => $id, 'type' => 'pvz', 'address' => $address,
            'city' => 'Ставрополь', 'city_key' => 'ставрополь', 'region' => 'Ставропольский край', 'region_key' => 'ставропольский', 'is_active' => true]);
    }

    public function test_hidden_point_is_not_on_the_map_and_cannot_be_chosen(): void
    {
        $this->point('ya-ok', 'Ставрополь г Ленина ул 1');
        $this->point('ya-far', 'Ставрополь г Дениса Зорина ул 35');
        DeliverySettings::setHiddenPoints('yataxi', ['ya-far']);

        $ids = collect($this->getJson('/delivery/points?method=yandex_point&city=Ставрополь')->assertOk()->json('points'))->pluck('id')->all();
        $this->assertSame(['ya-ok'], $ids);
        $gateway = app(DeliveryGateway::class);
        $this->assertNull($gateway->point('ya-far', 'Ставрополь', 'yataxi'));
        $this->assertSame('ya-ok', $gateway->point('ya-ok', 'Ставрополь', 'yataxi')['id']);
    }

    public function test_migration_hides_the_two_far_yandex_points_in_stavropol(): void
    {
        $this->point('ya-ok', 'Ставрополь г Ленина ул 1');
        $this->point('ya-zorina', 'Ставрополь г Дениса Зорина ул 35');
        $this->point('ya-avangard', 'Ставрополь г Авангардная ул 6а');
        $this->point('ya-zorina-2', 'Ставрополь г Дениса Зорина ул 35/2');

        (require base_path('database/migrations/2026_11_04_120000_hide_far_yandex_points_in_stavropol.php'))->up();

        $hidden = DeliverySettings::hiddenPoints('yataxi');
        sort($hidden);
        $this->assertSame(['ya-avangard', 'ya-zorina'], $hidden);
    }

    public function test_outliers_command_finds_and_hides_far_points(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->point('ya-'.$id, 'Ставрополь г Ленина ул '.$id);
        }
        $this->point('ya-far', 'Ставрополь г Авангардная ул 6а');
        Http::fake(['*/pricing-calculator' => fn (Request $request) => Http::response($request['destination']['platform_station_id'] === 'ya-far'
            ? ['pricing_total' => '918.59 RUB', 'delivery_days' => 9] : ['pricing_total' => '158.53 RUB', 'delivery_days' => 3])]);

        $this->artisan('delivery:outliers', ['provider' => 'yataxi', '--city' => 'Ставрополь'])
            ->expectsOutputToContain('ya-far')->expectsOutputToContain('--hide')->assertSuccessful();
        $this->assertSame([], DeliverySettings::hiddenPoints('yataxi'));

        $this->artisan('delivery:outliers', ['provider' => 'yataxi', '--city' => 'Ставрополь', '--hide' => true])->assertSuccessful();
        $this->assertSame(['ya-far'], DeliverySettings::hiddenPoints('yataxi'));
    }

    public function test_admin_edits_hidden_points_list(): void
    {
        $this->point('ya-far', 'Ставрополь г Авангардная ул 6а');
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(DeliveryIntegrations::class)
            ->assertSee('Скрытые пункты выдачи')
            ->set('data.yataxi.hidden', "ya-far — Авангардная 6а\n\n  ya-other  \nya-far")
            ->call('save');

        $this->assertSame(['ya-far', 'ya-other'], DeliverySettings::hiddenPoints('yataxi'));
        Livewire::test(DeliveryIntegrations::class)->assertSee('ya-far — Ставрополь г Авангардная ул 6а');
    }
}
