<?php

namespace App\Delivery\Carriers;

use App\Delivery\DeliverySettings;
use App\Models\DeliveryPoint;
use App\Models\Order;
use App\Support\Fulfillment;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * СДЭК, API v2: посылка от пункта приёма СДЭК до ПВЗ или постамата.
 *
 * Боевой адрес — api.cdek.ru/v2, учебный — api.edu.cdek.ru/v2 с открытыми
 * тестовыми ключами СДЭК (CDEK_TEST=true).
 *
 * Что важно:
 *  — тариф должен совпадать с типом пункта: до ПВЗ — «склад-склад» (136 и
 *    родственные, режим 4), до постамата — «склад-постамат» (368, режим 7);
 *  — заказ создаётся асинхронно: сразу приходит uuid, номер СДЭК — чуть
 *    позже; номер заказа сайта уходит полем number, по нему заказ ищется;
 *  — статусы в ответе в неочевидном порядке — сортируем по времени сами;
 *  — этикетка (ШК места) готовится несколько секунд, ссылка живёт час.
 */
class CdekCarrier extends HttpCarrier
{
    /** Открытые ключи учебной среды СДЭК (api-docs.cdek.ru/33828751.html). */
    private const TEST_ID = 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP';

    private const TEST_SECRET = 'RmAmgvSgSl1yirlz9QupbzOJVqhCxcP5';

    /** Режим доставки СДЭК: 4 — склад→ПВЗ, 7 — склад→постамат. */
    private const MODE_PVZ = 4;

    private const MODE_POSTAMAT = 7;

    public function key(): string
    {
        return 'cdek';
    }

    public function title(): string
    {
        return 'СДЭК';
    }

    public function isConfigured(): bool
    {
        return $this->test() || (filled(DeliverySettings::credential('cdek', 'client_id'))
            && filled(DeliverySettings::credential('cdek', 'client_secret')));
    }

    private function test(): bool
    {
        return (bool) config('livsi.cdek.test');
    }

    protected function baseUrl(): string
    {
        return (string) (config('livsi.cdek.api_url') ?: ($this->test() ? 'https://api.edu.cdek.ru/v2' : 'https://api.cdek.ru/v2'));
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token()];
    }

    private function credentials(): array
    {
        return $this->test()
            ? [self::TEST_ID, self::TEST_SECRET]
            : [(string) DeliverySettings::credential('cdek', 'client_id'), (string) DeliverySettings::credential('cdek', 'client_secret')];
    }

    private function tokenKey(): string
    {
        return 'cdek-token:'.hash('sha256', $this->baseUrl().'|'.$this->credentials()[0]);
    }

    private function token(): string
    {
        $cached = Cache::get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        [$id, $secret] = $this->credentials();
        // Параметры — в строке запроса, как делает официальный модуль СДЭК.
        $response = Http::acceptJson()->connectTimeout(4)->timeout(15)
            ->post(rtrim($this->baseUrl(), '/').'/oauth/token?'.http_build_query([
                'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret,
            ]));
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('СДЭК не принял ключи доступа (HTTP '.$response->status().'). Проверьте Account и Secure password.');
        }
        $ttl = max(60, (int) ($response->json('expires_in') ?: 1200) - 60);
        Cache::put($this->tokenKey(), $response->json('access_token'), $ttl);

        return $response->json('access_token');
    }

    /** Запрос с одной повторной попыткой, если токен истёк раньше срока. */
    private function call(string $method, string $path, array $data = [], int $timeout = 20): Response
    {
        $send = fn (): Response => match ($method) {
            'get' => $this->http($timeout)->get($path, $data),
            'post' => $this->http($timeout)->post($path, $data),
            'delete' => $this->http($timeout)->delete($path, $data),
        };
        $response = $send();
        if ($response->status() === 401) {
            Cache::forget($this->tokenKey());
            $response = $send();
        }

        return $response;
    }

    public function allPoints(): iterable
    {
        $size = 1000;
        for ($page = 0; $page < 200; $page++) {
            $response = $this->ok($this->call('get', '/deliverypoints', [
                'country_code' => config('livsi.delivery.country_code', 'RU'),
                'is_handout' => 'true', 'type' => 'ALL', 'size' => $size, 'page' => $page,
            ], 90), 'список ПВЗ');
            $rows = $response->json();
            if (! is_array($rows) || $rows === []) {
                return;
            }
            foreach ($rows as $point) {
                if (is_array($point) && ($mapped = $this->mapPoint($point))) {
                    yield $mapped;
                }
            }
            if (count($rows) < $size) {
                return;
            }
        }
    }

    /** Пункты приёма в городе — куда сдавать посылки, для выбора в настройках. */
    public function dropoffPoints(string $city): array
    {
        $cities = $this->ok($this->call('get', '/location/cities', ['country_codes' => 'RU', 'city' => $city]), 'поиск города')->json();
        $code = is_array($cities) ? ($cities[0]['code'] ?? null) : null;
        if (! is_numeric($code)) {
            return [];
        }
        $rows = $this->ok($this->call('get', '/deliverypoints', ['city_code' => (int) $code, 'is_reception' => 'true', 'type' => 'PVZ']),
            'пункты приёма')->json();

        return array_values(array_filter(array_map(fn ($point) => is_array($point) ? $this->mapPoint($point) : null,
            is_array($rows) ? $rows : [])));
    }

    /** @param array<string, mixed> $point */
    private function mapPoint(array $point): ?array
    {
        if (! is_string($point['code'] ?? null) || (($point['status'] ?? 'ACTIVE') !== 'ACTIVE')) {
            return null;
        }
        $location = is_array($point['location'] ?? null) ? $point['location'] : [];

        return [
            'external_id' => $point['code'],
            'type' => ($point['type'] ?? null) === 'POSTAMAT' ? 'postamat' : 'pvz',
            'name' => (string) ($point['name'] ?? ''),
            'address' => (string) ($location['address_full'] ?? $location['address'] ?? ''),
            'city' => (string) ($location['city'] ?? ''),
            'region' => (string) ($location['region'] ?? ''),
            'lat' => $location['latitude'] ?? null,
            'lng' => $location['longitude'] ?? null,
            'timetable' => (string) ($point['work_time'] ?? ''),
            'limits' => array_filter([
                'city_code' => is_numeric($location['city_code'] ?? null) ? (int) $location['city_code'] : null,
                'weight_max_g' => is_numeric($point['weight_max'] ?? null) && $point['weight_max'] > 0 ? (int) round($point['weight_max'] * 1000) : null,
                'cells' => is_array($point['dimensions'] ?? null) && $point['dimensions'] ? array_values($point['dimensions']) : null,
                // Принимает ли пункт посылки — нужно для возвратов: покупатель сдаёт товар туда.
                'reception' => is_bool($point['is_reception'] ?? null) ? $point['is_reception'] : null,
            ], fn ($value) => $value !== null),
        ];
    }

    public function tariffs(array $parcel, array $point, array $context): array
    {
        $stored = DeliveryPoint::query()->where('provider', 'cdek')->where('external_id', (string) $point['id'])->first();
        $toCity = $stored?->limits['city_code'] ?? null;
        if (! $stored || ! $toCity) {
            return [];
        }
        $postamat = $stored->type === 'postamat';
        if ($postamat && ! $this->fitsCell($parcel, $stored->limits['cells'] ?? [])) {
            throw ValidationException::withMessages(['delivery_quote' => 'Посылка не помещается в ячейку этого постамата. Выберите пункт выдачи.']);
        }
        $weightMax = $stored->limits['weight_max_g'] ?? null;
        if ($weightMax && $parcel['weight'] > $weightMax) {
            throw ValidationException::withMessages(['delivery_quote' => 'Посылка тяжелее, чем принимает этот пункт. Выберите другой.']);
        }
        $from = ['code' => $this->originCityCode()];
        $packages = [['weight' => $parcel['weight'], 'length' => $parcel['length'], 'width' => $parcel['width'], 'height' => $parcel['height']]];
        $body = $this->ok($this->call('post', '/calculator/tarifflist', [
            'type' => 1, 'currency' => 1, 'lang' => 'rus',
            'from_location' => $from, 'to_location' => ['code' => (int) $toCity], 'packages' => $packages,
        ]), 'расчёт')->json();

        $allowed = array_map('intval', array_filter(array_map('trim', explode(',', (string) config('livsi.cdek.tariffs', '136,368,234,378,483,486')))));
        $mode = $postamat ? self::MODE_POSTAMAT : self::MODE_PVZ;
        $found = [];
        foreach ($body['tariff_codes'] ?? [] as $tariff) {
            $code = (int) ($tariff['tariff_code'] ?? 0);
            $sum = $this->kopecks($tariff['delivery_sum'] ?? null);
            if ((int) ($tariff['delivery_mode'] ?? 0) !== $mode || ! in_array($code, $allowed, true) || $sum === null) {
                continue;
            }
            $found[] = ['tariff_id' => (string) $code, 'tariff_name' => (string) ($tariff['tariff_name'] ?? ''), 'cost' => $sum,
                'days_min' => $tariff['calendar_min'] ?? $tariff['period_min'] ?? null,
                'days_max' => $tariff['calendar_max'] ?? $tariff['period_max'] ?? null];
        }
        usort($found, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
        if (! $found) {
            return [];
        }

        // Страховка считается от объявленной стоимости товаров — уточняем
        // самый дешёвый тариф с ней, чтобы цена совпала со счётом СДЭК.
        $best = $found[0];
        try {
            $exact = $this->ok($this->call('post', '/calculator/tariff', [
                'type' => 1, 'currency' => 1, 'tariff_code' => (int) $best['tariff_id'],
                'from_location' => $from, 'to_location' => ['code' => (int) $toCity], 'packages' => $packages,
                'services' => [['code' => 'INSURANCE', 'parameter' => (string) round(((int) ($context['assessed'] ?? 0)) / 100, 2)]],
            ]), 'расчёт со страховкой')->json();
            $total = $this->kopecks($exact['total_sum'] ?? null);
            if ($total !== null && $total >= $best['cost']) {
                $best['cost'] = $total;
            }
        } catch (RuntimeException) {
            // без уточнения — базовая цена тарифа
        }

        return [$best];
    }

    /** Посылка влезает в ячейку постамата: стороны по возрастанию — каждая не больше. */
    private function fitsCell(array $parcel, array $cells): bool
    {
        if ($cells === []) {
            return false;
        }
        $box = [$parcel['length'], $parcel['width'], $parcel['height']];
        sort($box);
        foreach ($cells as $cell) {
            $size = [(float) ($cell['width'] ?? 0), (float) ($cell['height'] ?? 0), (float) ($cell['depth'] ?? 0)];
            sort($size);
            if ($box[0] <= $size[0] && $box[1] <= $size[1] && $box[2] <= $size[2]) {
                return true;
            }
        }

        return false;
    }

    private function originCityCode(): int
    {
        $origin = $this->origin();

        return (int) Cache::remember('cdek-origin-city:'.hash('sha256', $this->baseUrl().'|'.$origin), now()->addDay(), function () use ($origin): int {
            $rows = $this->ok($this->call('get', '/deliverypoints', ['code' => $origin]), 'пункт приёма')->json();
            $code = is_array($rows) ? ($rows[0]['location']['city_code'] ?? null) : null;
            if (! is_numeric($code)) {
                throw new RuntimeException('СДЭК не знает пункт приёма '.$origin.'. Проверьте номер в настройках сайта.');
            }

            return (int) $code;
        });
    }

    public function createShipment(Order $order, array $sender): array
    {
        $parcel = $this->parcel($order);
        $items = [];
        foreach ($this->units($order) as $unit) {
            $variant = $unit['item']->variant;
            $items[] = array_filter([
                'name' => mb_substr($unit['item']->label(), 0, 255),
                'ware_key' => mb_substr((string) $unit['item']->sku, 0, 50),
                'payment' => ['value' => 0],
                'cost' => round($unit['unit_price'] / 100, 2),
                'weight' => max(1, (int) ($variant?->weight_g ?? 1)),
                'amount' => $unit['count'],
                'marking' => $unit['mark'],
            ], fn ($value) => $value !== null);
        }
        $response = $this->call('post', '/orders', [
            'type' => 1,
            'number' => $order->shipmentRef(),
            'tariff_code' => (int) ($order->delivery_quote['tariff_id'] ?? 0),
            'comment' => 'livsi.shop '.$order->number,
            'shipment_point' => $this->origin(),
            'delivery_point' => (string) $order->delivery_point_id,
            'recipient' => array_filter([
                'name' => mb_substr($order->customer_name, 0, 255),
                'email' => $order->customer_email ?: null,
                'phones' => [['number' => $this->recipientPhone($order)]],
            ]),
            'packages' => [['number' => '1', 'weight' => $parcel['weight'], 'length' => $parcel['length'],
                'width' => $parcel['width'], 'height' => $parcel['height'], 'items' => $items]],
        ], 40);
        $this->rejectInvalid($response);
        $uuid = $this->ok($response, 'создание заказа')->json('entity.uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new RuntimeException('СДЭК не вернул номер заказа.');
        }

        // Номер СДЭК появляется через секунду-другую. Подождём немного,
        // а если не успел — его подтянет первая же проверка статуса.
        for ($i = 0; $i < 4; $i++) {
            usleep(1_200_000);
            $info = $this->call('get', '/orders/'.$uuid);
            if (! $info->successful()) {
                continue;
            }
            $this->rejectInvalid($info);
            if ($info->json('entity.cdek_number')) {
                return ['external_id' => $uuid, ...$this->describe($info->json())];
            }
        }

        return ['external_id' => $uuid, 'tracking' => null, 'status_code' => 'ACCEPTED',
            'status_name' => 'Принят СДЭК, номер присваивается', 'data' => []];
    }

    /** Отказ СДЭК в данных заказа — понятной ошибкой, а не «неизвестно». */
    private function rejectInvalid(Response $response): void
    {
        foreach ((array) $response->json('requests') as $request) {
            if (($request['state'] ?? null) === 'INVALID') {
                $errors = array_map(fn ($error) => (string) ($error['message'] ?? $error['code'] ?? ''), (array) ($request['errors'] ?? []));
                throw ValidationException::withMessages(['shipment' => 'СДЭК отклонил заказ: '.mb_substr(implode('; ', array_filter($errors)) ?: 'без пояснения', 0, 220)]);
            }
        }
    }

    public function findShipment(Order $order): ?array
    {
        $response = $this->call('get', '/orders', ['im_number' => $order->shipmentRef()]);
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }
        $body = $this->ok($response, 'поиск заказа')->json();
        $uuid = $body['entity']['uuid'] ?? null;
        foreach ((array) ($body['requests'] ?? []) as $request) {
            if (($request['state'] ?? null) === 'INVALID') {
                return null;
            }
        }

        return is_string($uuid) ? ['external_id' => $uuid, ...$this->describe($body)] : null;
    }

    public function shipmentInfo(Order $order): array
    {
        $response = $this->call('get', '/orders/'.$order->shipment_external_id);
        $this->rejectInvalid($response);
        $state = $this->describe($this->ok($response, 'статус заказа')->json());

        return [...$state, 'fulfillment' => $this->fulfillment($state['status_code'])];
    }

    /** @return array{tracking: ?string, status_code: ?string, status_name: ?string, data: array} */
    private function describe(array $body): array
    {
        $entity = is_array($body['entity'] ?? null) ? $body['entity'] : [];
        $statuses = array_values(array_filter((array) ($entity['statuses'] ?? []),
            fn ($status) => is_array($status) && empty($status['deleted']) && is_string($status['code'] ?? null)));
        usort($statuses, fn (array $a, array $b): int => $this->time($b['date_time'] ?? null) <=> $this->time($a['date_time'] ?? null));
        $current = $statuses[0] ?? null;
        $number = isset($entity['cdek_number']) && is_scalar($entity['cdek_number']) ? (string) $entity['cdek_number'] : null;

        return [
            'tracking' => $number,
            'status_code' => $current['code'] ?? null,
            'status_name' => $current['name'] ?? null,
            'data' => array_filter([
                'number' => $number,
                'tracking_url' => $number ? 'https://www.cdek.ru/ru/tracking?order_id='.$number : null,
                'keep_free_until' => is_string($entity['keep_free_until'] ?? null) ? $entity['keep_free_until'] : null,
            ]),
        ];
    }

    private function time(mixed $value): int
    {
        try {
            return is_string($value) ? Carbon::parse($value)->getTimestamp() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function fulfillment(?string $status): ?string
    {
        return match (true) {
            $status === null => null,
            in_array($status, ['ACCEPTED_AT_PICK_UP_POINT', 'POSTOMAT_POSTED'], true) => Fulfillment::AT_PICKUP,
            in_array($status, ['DELIVERED', 'POSTOMAT_RECEIVED'], true) => Fulfillment::DELIVERED,
            in_array($status, ['NOT_DELIVERED', 'POSTOMAT_SEIZED', 'SENT_TO_SENDER_CITY', 'ACCEPTED_IN_SENDER_CITY'], true) => Fulfillment::RETURNED,
            in_array($status, ['ACCEPTED', 'CREATED', 'INVALID', 'REMOVED'], true) => null,
            // всё остальное — посылка у СДЭК и едет: склад, транзит, город получателя
            default => Fulfillment::SHIPPED,
        };
    }

    public function label(Order $order, string $size = '75x120'): string
    {
        [$width] = array_map('intval', explode('x', $size) + [0, 0]);
        $created = $this->ok($this->call('post', '/print/barcodes', [
            'orders' => [['order_uuid' => $order->shipment_external_id]],
            'copy_count' => 1, 'format' => $width <= 80 ? 'A7' : 'A6', 'lang' => 'RUS',
        ]), 'этикетка')->json('entity.uuid');
        if (! is_string($created)) {
            throw new RuntimeException('СДЭК не принял запрос этикетки.');
        }
        for ($i = 0; $i < 10; $i++) {
            usleep(1_500_000);
            $entity = $this->call('get', '/print/barcodes/'.$created)->json('entity');
            $codes = array_column((array) ($entity['statuses'] ?? []), 'code');
            if (in_array('INVALID', $codes, true)) {
                throw new RuntimeException('СДЭК не смог подготовить этикетку — проверьте заказ в кабинете СДЭК.');
            }
            if (is_string($entity['url'] ?? null)) {
                return $this->ok($this->http(30)->withHeaders(['Accept' => 'application/pdf'])->get($entity['url']), 'файл этикетки')->body();
            }
        }

        throw new RuntimeException('СДЭК ещё готовит этикетку. Повторите через минуту.');
    }

    /**
     * Клиентский возврат: покупатель сдаёт посылку в пункт СДЭК, она едет
     * в наш пункт приёма (тот, куда сдаём заказы). Оплата — по нашему
     * договору; номер заказа у СДЭК — номер заявки на возврат.
     *
     * Метод СДЭК «clientReturn» годится только для полного возврата заказа,
     * который вёз СДЭК. Обычная регистрация с is_client_return подходит
     * всем: частичному возврату и заказам других служб.
     *
     * @param  array{name: string, phone: string}  $sender  покупатель
     * @param  array{name: string, phone: string}  $recipient  мы
     * @param  array{weight: int, length: int, width: int, height: int}  $parcel
     * @param  list<array{name: string, sku: string, cost: int, weight: int, amount: int}>  $items  cost — копейки за штуку
     * @return array{external_id: string, tracking: ?string, status_code: ?string, status_name: ?string, cost: ?int}
     */
    public function createClientReturn(string $number, string $fromPoint, array $sender, array $recipient, array $parcel, array $items, string $comment): array
    {
        $response = $this->call('post', '/orders', [
            'type' => 1,
            'number' => $number,
            'tariff_code' => (int) config('livsi.cdek.return_tariff', 136),
            'is_client_return' => true,
            'comment' => mb_substr($comment, 0, 255),
            'shipment_point' => $fromPoint,
            'delivery_point' => $this->origin(),
            'sender' => ['name' => mb_substr($sender['name'], 0, 255), 'phones' => [['number' => $sender['phone']]]],
            'recipient' => ['name' => mb_substr($recipient['name'], 0, 255), 'phones' => [['number' => $recipient['phone']]]],
            'packages' => [['number' => '1', 'weight' => $parcel['weight'], 'length' => $parcel['length'],
                'width' => $parcel['width'], 'height' => $parcel['height'],
                'items' => array_map(fn (array $item): array => [
                    'name' => mb_substr($item['name'], 0, 255),
                    'ware_key' => mb_substr($item['sku'] ?: 'item', 0, 50),
                    'payment' => ['value' => 0],
                    'cost' => round($item['cost'] / 100, 2),
                    'weight' => max(1, $item['weight']),
                    'amount' => max(1, $item['amount']),
                ], $items)]],
        ], 40);
        $this->rejectInvalid($response);
        $uuid = $this->ok($response, 'создание возврата')->json('entity.uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new RuntimeException('СДЭК не вернул номер возврата.');
        }

        for ($i = 0; $i < 4; $i++) {
            usleep((int) config('livsi.cdek.poll_microseconds', 1_200_000));
            $info = $this->call('get', '/orders/'.$uuid);
            if (! $info->successful()) {
                continue;
            }
            $this->rejectInvalid($info);
            if ($info->json('entity.cdek_number')) {
                return ['external_id' => $uuid, ...$this->returnState($info->json())];
            }
        }

        return ['external_id' => $uuid, 'tracking' => null, 'status_code' => 'ACCEPTED',
            'status_name' => 'Принят СДЭК, номер присваивается', 'cost' => null];
    }

    /** Возврат по номеру заявки — если ответ на создание потерялся. */
    public function findClientReturn(string $number): ?array
    {
        $response = $this->call('get', '/orders', ['im_number' => $number]);
        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }
        $body = $this->ok($response, 'поиск возврата')->json();
        foreach ((array) ($body['requests'] ?? []) as $request) {
            if (($request['state'] ?? null) === 'INVALID') {
                return null;
            }
        }
        $uuid = $body['entity']['uuid'] ?? null;

        return is_string($uuid) ? ['external_id' => $uuid, ...$this->returnState($body)] : null;
    }

    /** Где сейчас посылка возврата и сколько стоит перевозка. */
    public function clientReturnInfo(string $uuid): array
    {
        $response = $this->call('get', '/orders/'.$uuid);
        $this->rejectInvalid($response);

        return $this->returnState($this->ok($response, 'статус возврата')->json());
    }

    /** @return array{tracking: ?string, status_code: ?string, status_name: ?string, cost: ?int} */
    private function returnState(array $body): array
    {
        $state = $this->describe($body);
        $detail = is_array($body['entity']['delivery_detail'] ?? null) ? $body['entity']['delivery_detail'] : [];

        return ['tracking' => $state['tracking'], 'status_code' => $state['status_code'], 'status_name' => $state['status_name'],
            'cost' => $this->kopecks($detail['total_sum'] ?? $detail['delivery_sum'] ?? null)];
    }

    /** Отменить возврат, пока посылку не сдали. */
    public function cancelClientReturn(string $uuid): void
    {
        $response = $this->call('delete', '/orders/'.$uuid);
        $this->rejectInvalid($response);
        $this->ok($response, 'отмена возврата');
    }

    public function cancel(Order $order): void
    {
        $response = $this->call('delete', '/orders/'.$order->shipment_external_id);
        $this->rejectInvalid($response);
        $this->ok($response, 'отмена');
    }
}
