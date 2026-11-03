<?php

namespace App\Delivery\Carriers;

use App\Delivery\DeliverySettings;
use App\Models\Order;
use App\Support\Fulfillment;
use RuntimeException;

/**
 * Яндекс Доставка, «доставка в другой день» до ПВЗ и постаматов.
 *
 * Документация: yandex.com/support/delivery-profile/ru/api/other-day/
 * Боевой адрес — b2b-authproxy.taxi.yandex.net, тестовый —
 * b2b.taxi.tst.yandex.net (в тесте работает только Москва).
 *
 * Особенности, которые легко забыть:
 *  — в запросах деньги в копейках, в ответах — строка в рублях «225.7 RUB»;
 *  — в калькуляторе пункт отправки — плоское platform_station_id, а при
 *    создании заказа — platform_station.platform_id;
 *  — коды маркировки — по одному на строку товара, поэтому маркированный
 *    товар уходит строками по одной штуке;
 *  — номер заказа сайта уходит как operator_request_id: по нему заказ
 *    находится, если ответ на создание потерялся, и второй не создаётся;
 *  — вебхуков нет, статусы только опросом.
 */
class YandexCarrier extends HttpCarrier
{
    private const LABEL_SIZES = ['58x40', '58x60', '75x120', '100x150'];

    public function key(): string
    {
        return 'yataxi';
    }

    public function title(): string
    {
        return 'Яндекс Доставка';
    }

    public function isConfigured(): bool
    {
        return $this->test()
            ? filled(config('livsi.yandex_delivery.test_token'))
            : filled(DeliverySettings::credential('yataxi', 'token'));
    }

    private function test(): bool
    {
        return (bool) config('livsi.yandex_delivery.test');
    }

    protected function baseUrl(): string
    {
        return (string) (config('livsi.yandex_delivery.api_url')
            ?: ($this->test() ? 'https://b2b.taxi.tst.yandex.net' : 'https://b2b-authproxy.taxi.yandex.net'));
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.($this->test()
                ? (string) config('livsi.yandex_delivery.test_token')
                : (string) DeliverySettings::credential('yataxi', 'token')),
            'Accept-Language' => 'ru',
        ];
    }

    /**
     * Весь список ПВЗ России одним ответом — это десятки мегабайт. Поэтому
     * берём полосами по широте: каждая полоса — обычный по размеру ответ.
     */
    public function allPoints(): iterable
    {
        for ($lat = 41.0; $lat < 82.0; $lat += 0.5) {
            $body = $this->ok($this->http(90)->post('/api/b2b/platform/pickup-points/list', [
                'payment_method' => 'already_paid',
                'latitude' => ['from' => $lat, 'to' => $lat + 0.5],
                'longitude' => ['from' => 19.0, 'to' => 180.0],
            ]), 'список ПВЗ')->json('points');
            foreach (is_array($body) ? $body : [] as $point) {
                if (is_array($point) && ($mapped = $this->mapPoint($point))) {
                    yield $mapped;
                }
            }
        }
    }

    /** Пункты, куда можно сдать посылки, в городе — для выбора в настройках. */
    public function dropoffPoints(string $city): array
    {
        $variants = $this->ok($this->http()->post('/api/b2b/platform/location/detect', ['location' => $city]), 'поиск города')
            ->json('variants');
        $geoId = $variants[0]['geo_id'] ?? null;
        if (! is_int($geoId)) {
            return [];
        }
        $points = $this->ok($this->http(60)->post('/api/b2b/platform/pickup-points/list', [
            'geo_id' => $geoId, 'available_for_dropoff' => true,
        ]), 'пункты приёма')->json('points');

        return array_values(array_filter(array_map(fn ($point) => is_array($point) ? $this->mapPoint($point) : null,
            is_array($points) ? $points : [])));
    }

    /** @param array<string, mixed> $point */
    private function mapPoint(array $point): ?array
    {
        if (! is_string($point['id'] ?? null) || ($point['type'] ?? null) === 'warehouse') {
            return null;
        }
        $address = is_array($point['address'] ?? null) ? $point['address'] : [];

        return [
            'external_id' => $point['id'],
            'type' => ($point['type'] ?? null) === 'terminal' ? 'postamat' : 'pvz',
            'name' => (string) ($point['name'] ?? ''),
            'address' => (string) ($address['full_address'] ?? ''),
            'city' => (string) ($address['locality'] ?? ''),
            'region' => (string) ($address['region'] ?? ''),
            'lat' => $point['position']['latitude'] ?? null,
            'lng' => $point['position']['longitude'] ?? null,
            'timetable' => $this->timetable($point['schedule'] ?? null),
            'limits' => null,
        ];
    }

    private function timetable(mixed $schedule): string
    {
        if (! is_array($schedule) || ! is_array($schedule['restrictions'] ?? null)) {
            return '';
        }
        $days = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $parts = [];
        foreach ($schedule['restrictions'] as $rule) {
            $d = array_values(array_filter((array) ($rule['days'] ?? []), fn ($day) => isset($days[$day])));
            if (! $d) {
                continue;
            }
            $span = count($d) > 1 && end($d) - $d[0] === count($d) - 1
                ? $days[$d[0]].'–'.$days[end($d)]
                : implode(', ', array_map(fn ($day) => $days[$day], $d));
            $from = $rule['time_from'] ?? null;
            $to = $rule['time_to'] ?? null;
            $parts[] = $span.' '.(is_array($from) && is_array($to)
                ? sprintf('%02d:%02d–%02d:%02d', $from['hours'] ?? 0, $from['minutes'] ?? 0, $to['hours'] ?? 0, $to['minutes'] ?? 0)
                : 'круглосуточно');
        }

        return implode('; ', $parts);
    }

    public function tariffs(array $parcel, array $point, array $context): array
    {
        $body = $this->ok($this->http()->post('/api/b2b/platform/pricing-calculator', [
            'source' => ['platform_station_id' => $this->origin()],
            'destination' => ['platform_station_id' => (string) $point['id']],
            'tariff' => 'self_pickup',
            'total_weight' => $parcel['weight'],
            'total_assessed_price' => (int) ($context['assessed'] ?? 0),
            'client_price' => 0,
            'payment_method' => 'already_paid',
            'places' => [['physical_dims' => ['weight_gross' => $parcel['weight'],
                'dx' => $parcel['length'], 'dy' => $parcel['width'], 'dz' => $parcel['height']]]],
        ]), 'расчёт')->json();
        $cost = $this->kopecks($body['pricing_total'] ?? null);
        if ($cost === null) {
            return [];
        }
        $days = is_int($body['delivery_days'] ?? null) ? $body['delivery_days'] : null;

        return [['tariff_id' => 'self_pickup', 'tariff_name' => 'До пункта выдачи', 'cost' => $cost,
            'days_min' => $days, 'days_max' => $days]];
    }

    public function createShipment(Order $order, array $sender): array
    {
        $parcel = $this->parcel($order);
        $place = 'L'.preg_replace('/\D/', '', $order->number).'-1';
        $items = [];
        foreach ($this->units($order) as $unit) {
            $variant = $unit['item']->variant;
            $vat = $this->vat($unit['item']);
            $items[] = array_filter([
                'count' => $unit['count'],
                'name' => mb_substr($unit['item']->label(), 0, 250),
                'article' => $unit['item']->sku,
                'place_barcode' => $place,
                'billing_details' => [
                    'unit_price' => $unit['unit_price'],
                    'assessed_unit_price' => max($unit['unit_price'], 100),
                    'nds' => in_array($vat, [0, 5, 7, 10, 22], true) ? $vat : -1,
                ],
                'physical_dims' => $variant ? ['dx' => max(1, (int) ceil($variant->length_mm / 10)),
                    'dy' => max(1, (int) ceil($variant->height_mm / 10)), 'dz' => max(1, (int) ceil($variant->width_mm / 10))] : null,
                'marking_code' => $unit['mark'],
            ], fn ($value) => $value !== null);
        }

        $response = $this->http(40)->post('/api/b2b/platform/request/create?send_unix=false', [
            'info' => ['operator_request_id' => $order->number, 'comment' => 'livsi.shop '.$order->number],
            'source' => ['platform_station' => ['platform_id' => $this->origin()]],
            'destination' => ['type' => 'platform_station', 'platform_station' => ['platform_id' => (string) $order->delivery_point_id]],
            'items' => $items,
            'places' => [['barcode' => $place, 'physical_dims' => ['weight_gross' => $parcel['weight'],
                'dx' => $parcel['length'], 'dy' => $parcel['width'], 'dz' => $parcel['height']]]],
            'billing_info' => ['payment_method' => 'already_paid', 'delivery_cost' => 0],
            // На этикетке и в акте Яндекс печатает только first_name — кладём туда имя целиком.
            'recipient_info' => array_filter(['first_name' => mb_substr($order->customer_name, 0, 120),
                'phone' => $this->recipientPhone($order), 'email' => $order->customer_email ?: null]),
            'last_mile_policy' => 'self_pickup',
            'particular_items_refuse' => false,
        ]);
        if (str_contains($this->errorText($response), 'already was request with such code')) {
            return $this->findShipment($order) ?? throw new RuntimeException('Яндекс считает заказ созданным, но не находит его.');
        }
        $id = $this->ok($response, 'создание заказа')->json('request_id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Яндекс не вернул номер заказа.');
        }

        return ['external_id' => $id, 'tracking' => null, 'status_code' => 'CREATED', 'status_name' => 'Заказ создан в Яндексе', 'data' => []];
    }

    public function findShipment(Order $order): ?array
    {
        $response = $this->http()->get('/api/b2b/platform/request/info', ['request_code' => $order->number]);
        if ($response->status() === 404) {
            return null;
        }
        $info = $this->ok($response, 'поиск заказа')->json();
        if (! is_string($info['request_id'] ?? null)) {
            return null;
        }

        return ['external_id' => $info['request_id'], ...$this->describe($info)];
    }

    public function shipmentInfo(Order $order): array
    {
        $info = $this->ok($this->http()->get('/api/b2b/platform/request/info', ['request_id' => $order->shipment_external_id]),
            'статус заказа')->json();
        $state = $this->describe(is_array($info) ? $info : []);

        return [...$state, 'fulfillment' => $this->fulfillment($state['status_code'])];
    }

    /** @return array{tracking: ?string, status_code: ?string, status_name: ?string, data: array} */
    private function describe(array $info): array
    {
        $state = is_array($info['state'] ?? null) ? $info['state'] : [];

        return [
            'tracking' => is_scalar($info['courier_order_id'] ?? null) ? (string) $info['courier_order_id'] : null,
            'status_code' => is_string($state['status'] ?? null) ? $state['status'] : null,
            'status_name' => is_string($state['description'] ?? null) ? $state['description'] : null,
            'data' => array_filter([
                'tracking_url' => is_string($info['sharing_url'] ?? null) ? $info['sharing_url'] : null,
                'reason' => is_string($state['reason'] ?? null) ? $state['reason'] : null,
            ]),
        ];
    }

    private function fulfillment(?string $status): ?string
    {
        return match (true) {
            $status === null => null,
            in_array($status, ['SORTING_CENTER_AT_START', 'SORTING_CENTER_PREPARED', 'SORTING_CENTER_TRANSMITTED',
                'DELIVERY_AT_START', 'DELIVERY_TRANSPORTATION', 'DELIVERY_AT_START_SORT'], true) => Fulfillment::SHIPPED,
            in_array($status, ['DELIVERY_ARRIVED_PICKUP_POINT', 'CONFIRMATION_CODE_RECEIVED'], true) => Fulfillment::AT_PICKUP,
            in_array($status, ['DELIVERY_TRANSMITTED_TO_RECIPIENT', 'DELIVERY_DELIVERED', 'PARTICULARLY_DELIVERED'], true) => Fulfillment::DELIVERED,
            $status === 'SORTING_CENTER_RETURN_RETURNED', str_starts_with($status, 'RETURN_') => Fulfillment::RETURNED,
            default => null,
        };
    }

    public function label(Order $order, string $size = '75x120'): string
    {
        $response = $this->http(40)->withHeaders(['Accept' => 'application/pdf, application/json'])
            ->post('/api/b2b/platform/request/generate-labels', [
                'request_ids' => [$order->shipment_external_id],
                'generate_type' => 'one',
                'label_size_mm' => in_array($size, self::LABEL_SIZES, true) ? $size : '100x150',
                'language' => 'ru',
            ]);

        return $this->ok($response, 'этикетка')->body();
    }

    public function cancel(Order $order): void
    {
        $status = $this->ok($this->http()->post('/api/b2b/platform/request/cancel', ['request_id' => $order->shipment_external_id]),
            'отмена')->json('status');
        if ($status === 'ERROR') {
            throw new RuntimeException('Яндекс не отменил заказ — отмените его в кабинете Яндекс Доставки.');
        }
    }
}
