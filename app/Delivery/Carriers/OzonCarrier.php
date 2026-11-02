<?php

namespace App\Delivery\Carriers;

use App\Delivery\DeliverySettings;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Support\Fulfillment;
use App\Support\Phone;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Ozon Доставка для бизнеса — Delivery API (api-delivery.ozon.ru).
 *
 * Это не Seller API маркетплейса: отдельный кабинет «Ozon Доставка» со
 * своим балансом, товарам не нужно быть на Ozon, цену доставки считает
 * сам Ozon. Доступ — частное приложение типа Delivery API: client_id и
 * client_secret, токен по client_credentials, без подтверждения в браузере.
 *
 * Важное:
 *  — посылка описывается весом, размерами (миллиметры!) и объявленной
 *    стоимостью — состава и кодов маркировки Ozon не принимает;
 *  — деньги — строкой в рублях: {"amount": "1780.00", "currency_code": "RUB"};
 *  — откуда везём — «метод отгрузки» из кабинета (shipment_method_id);
 *  — создание заказа — с заголовком Idempotency-Key: ключ выводится из
 *    номера заказа, поэтому повтор после обрыва не создаёт второй заказ;
 *  — токен живёт до момента времени (expires_in — отметка, а не секунды);
 *  — Ozon иногда отвечает перенаправлением с кукой (защита от ботов):
 *    куки храним, запрос повторяем тот же.
 *
 * Порядок по документации Ozon Delivery API (выгрузка 02.11.2026):
 *   order/create → posting/approve (тут Ozon проверяет баланс) →
 *   статус forming → ready_for_shipping → posting/label (PDF, только в
 *   ready_for_shipping) → сдача в пункт отгрузки → on_way → in_delivery_point
 *   → delivered. Отмена — posting/cancel до in_courier_service/delivered.
 *   Грузоместа нужны только при доверительной приёмке — у нас её нет.
 */
class OzonCarrier extends HttpCarrier
{
    private const TOKEN_URL = 'https://xapi.ozon.ru/oauth/token';

    private ?CookieJar $jar = null;

    public function key(): string
    {
        return 'ozondel';
    }

    public function title(): string
    {
        return 'Ozon Доставка';
    }

    public function isConfigured(): bool
    {
        return filled(DeliverySettings::credential('ozondel', 'client_id'))
            && filled(DeliverySettings::credential('ozondel', 'client_secret'));
    }

    protected function baseUrl(): string
    {
        return (string) (config('livsi.ozon.api_url') ?: 'https://api-delivery.ozon.ru');
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token()];
    }

    protected function http(int $timeout = 15, int $connect = 4): PendingRequest
    {
        $this->jar ??= new CookieJar;

        return parent::http($timeout, $connect)->asJson()->withOptions([
            'cookies' => $this->jar,
            // Защита Ozon от ботов отвечает 302/307 с кукой: повторяем тот
            // же POST с тем же телом и только на тот же адрес.
            'allow_redirects' => ['max' => 3, 'strict' => true, 'protocols' => ['https'], 'referer' => false],
        ]);
    }

    private function tokenKey(): string
    {
        return 'ozon-delivery-token:'.hash('sha256', (string) DeliverySettings::credential('ozondel', 'client_id'));
    }

    private function token(): string
    {
        $cached = Cache::get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => (string) DeliverySettings::credential('ozondel', 'client_id'),
            'client_secret' => (string) DeliverySettings::credential('ozondel', 'client_secret'),
            'grant_type' => 'client_credentials',
            'scope' => ['delivery-api.all'],
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('Ozon не принял ключи приложения (HTTP '.$response->status().'). Проверьте client_id и client_secret.');
        }
        $expires = (int) ($response->json('expires_in') ?: 0);
        $ttl = $expires > 1_000_000_000 ? $expires - time() : ($expires ?: 1800);
        Cache::put($this->tokenKey(), $token, max(60, $ttl - 120));

        return $token;
    }

    private function post(string $path, array $body, int $timeout = 20, array $headers = []): Response
    {
        // replaceHeaders: свой Accept для этикетки заменяет JSON, а не добавляется вторым
        $response = $this->http($timeout)->replaceHeaders($headers)->post($path, $body);
        if ($response->status() === 401) {
            Cache::forget($this->tokenKey());
            $response = $this->http($timeout)->replaceHeaders($headers)->post($path, $body);
        }

        return $response;
    }

    /** Коды ошибок из документации — человеческим языком. */
    private const ERRORS = [
        'NotEnoughBalance' => 'на балансе Ozon Доставки не хватает денег — пополните баланс в кабинете Ozon Доставки',
        'PostingNotFound' => 'Ozon не нашёл отправление',
        'RecipientError' => 'Ozon не может доставить этому получателю (номер должен быть зарегистрирован на Ozon)',
        'DeliveryAvailabilityError' => 'Ozon не рассчитал доставку до этого пункта',
        'DeliveryCalculationError' => 'Ozon не смог посчитать доставку или страховку',
        'DeliveryPointNotFound' => 'Ozon не нашёл пункт выдачи',
        'SameDeliveryPointError' => 'пункт выдачи совпадает с пунктом отгрузки',
        'SamePointError' => 'пункт выдачи совпадает с пунктом отгрузки',
        'DeliveryPointRestrictionsError' => 'пункт не принимает посылку такого веса, размера или стоимости',
        'PrincipalError' => 'кабинет Ozon Доставки заблокирован',
        'ActiveInsuranceContractNotFound' => 'в кабинете Ozon Доставки нет действующего договора страхования',
    ];

    /** {"code": "...", "message": "..."} → текст: известный код по-русски, иначе сообщение Ozon. */
    private function describe(mixed $error): ?string
    {
        if (! $error) {
            return null;
        }
        if (! is_array($error)) {
            return (string) $error;
        }
        $code = (string) ($error['code'] ?? '');

        return self::ERRORS[$code] ?? (string) ($error['message'] ?? ($code !== '' ? $code : json_encode($error, JSON_UNESCAPED_UNICODE)));
    }

    protected function errorText(Response $response): string
    {
        $json = $response->json();
        if (is_array($json) && isset($json['error'])) {
            $text = $this->describe($json['error']);
            foreach ((array) ($json['details'] ?? []) as $detail) {
                if (is_array($detail) && ($more = $this->describe($detail['error'] ?? null)) && $more !== $text) {
                    $text .= '; '.$more;
                }
            }

            return (string) $text;
        }

        return parent::errorText($response);
    }

    /** Ошибка по одной позиции при HTTP 200 — в results[i].error. */
    private function itemError(mixed $item): ?string
    {
        return is_array($item) ? $this->describe($item['error'] ?? null) : null;
    }

    /** Проверка ключей: свежий токен и один пункт из списка. Ошибка — исключение с понятным текстом. */
    public function ping(): ?int
    {
        Cache::forget($this->tokenKey());
        $this->ok($this->post('/v1/delivery-point/list', ['pagination' => ['cursor' => null, 'limit' => 1]], 15), 'проверка связи');

        return null;
    }

    public function canDeliverTo(string $phone): bool
    {
        $result = $this->ok($this->post('/v1/delivery/check-client', ['phone_number' => $this->phone($phone)], 12), 'проверка покупателя')
            ->json('can_be_delivered');
        if (! is_bool($result)) {
            throw new RuntimeException('Ozon ответил на проверку покупателя непонятно.');
        }

        return $result;
    }

    private function phone(string $value): string
    {
        $phone = Phone::format($value);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Для доставки Ozon укажите полный российский номер телефона.']);
        }

        return $phone;
    }

    // ------------------------------------------------------------- пункты

    public function allPoints(): iterable
    {
        $cursor = null;
        $seen = [];
        $ids = [];
        $ours = [];
        $method = DeliverySettings::origin('ozondel');
        for ($page = 0; $page < 3000; $page++) {
            $body = $this->ok($this->post('/v1/delivery-point/list', ['pagination' => ['cursor' => $cursor, 'limit' => 100]], 60), 'список пунктов')->json();
            foreach ((array) ($body['delivery_points'] ?? []) as $point) {
                if (! is_numeric($point['delivery_point_id'] ?? null)) {
                    continue;
                }
                $ids[] = (int) $point['delivery_point_id'];
                // Ozon перечисляет методы доставки, которыми можно привезти в пункт
                $methods = array_map('strval', (array) ($point['shipment_method_ids'] ?? []));
                if ($method !== null && in_array((string) $method, $methods, true)) {
                    $ours[] = (int) $point['delivery_point_id'];
                }
            }
            $cursor = $body['next_cursor'] ?? null;
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
        }
        // Только пункты, куда возит наш метод отгрузки. Если Ozon ни у одного
        // пункта его не назвал (метод не указан или список без методов) —
        // берём все: лишний пункт отсеет расчёт, пустая карта хуже.
        // По 100: документация пишет «до 1000», но рабочий Ozon больше 100 не принимает.
        foreach (array_chunk(array_values(array_unique($ours ?: $ids)), 100) as $chunk) {
            foreach ($this->pointInfo($chunk) as $point) {
                if (is_array($point) && ($mapped = $this->mapPoint($point))) {
                    yield $mapped;
                }
            }
            usleep(150_000);
        }
    }

    /**
     * Сведения о пунктах. В списке Ozon бывают номера, которых он сам «не
     * находит» (закрытые пункты), — тогда он отвечает 404 на всю пачку и
     * перечисляет их в сообщении. Такие номера пропускаем и спрашиваем
     * остальные; если сообщение не разобрать — делим пачку пополам.
     *
     * @param  list<int>  $ids
     * @return list<mixed>
     */
    private function pointInfo(array $ids, int $depth = 0): array
    {
        $response = $this->post('/v1/delivery-point/info', ['delivery_point_ids' => $ids], 60);
        if ($response->status() === 404 && $depth < 12) {
            $missing = $this->missingPointIds($response, $ids);
            if ($missing) {
                $rest = array_values(array_diff($ids, $missing));

                return $rest ? $this->pointInfo($rest, $depth + 1) : [];
            }
            if (count($ids) === 1) {
                return [];
            }
            [$first, $second] = array_chunk($ids, (int) ceil(count($ids) / 2));

            return array_merge($this->pointInfo($first, $depth + 1), $this->pointInfo($second, $depth + 1));
        }
        $info = $this->ok($response, 'сведения о пунктах')->json('delivery_points');

        return is_array($info) ? array_values($info) : [];
    }

    /** Номера из «Не найдены пункты выдачи: 1,2,3» — только те, что мы спрашивали. @return list<int> */
    private function missingPointIds(Response $response, array $asked): array
    {
        $message = (string) ($response->json('error.message') ?? $response->json('message') ?? '');
        $tail = str_contains($message, ':') ? substr($message, strrpos($message, ':') + 1) : '';
        preg_match_all('/\d+/', $tail, $found);

        return array_values(array_intersect($asked, array_map('intval', $found[0])));
    }

    /** @param array<string, mixed> $point */
    private function mapPoint(array $point): ?array
    {
        if (($point['is_active'] ?? true) === false || ! is_numeric($point['delivery_point_id'] ?? null)) {
            return null;
        }
        $address = trim((string) ($point['full_address'] ?? ''));
        [$city, $region] = self::cityAndRegion($address);
        $type = mb_strtolower((string) ($point['type'] ?? ''));

        return [
            'external_id' => (string) $point['delivery_point_id'],
            'type' => str_contains($type, 'postamat') || str_contains($type, 'locker') || str_contains($type, 'постамат') ? 'postamat' : 'pvz',
            'name' => (string) ($point['name'] ?? ''),
            'address' => $address,
            'city' => $city,
            'region' => $region,
            'lat' => $point['coordinates']['latitude'] ?? null,
            'lng' => $point['coordinates']['longitude'] ?? null,
            'timetable' => '',
            'limits' => array_filter(['restrictions' => $point['restrictions'] ?? null, 'storage_days' => $point['storage_period_days'] ?? null]),
        ];
    }

    /**
     * Город и регион из полного адреса: отдельных полей Ozon не даёт.
     * «Ставропольский край, г. Ставрополь, ул. Ленина, 1» → Ставрополь,
     * Ставропольский край; «Москва, ул. …» → Москва, Москва.
     *
     * @return array{0: string, 1: string}
     */
    public static function cityAndRegion(string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)),
            fn (string $part): bool => $part !== '' && ! preg_match('/^\d{6}$/u', $part) && mb_strtolower($part) !== 'россия'));
        $federal = ['москва', 'санкт-петербург', 'севастополь'];
        $region = '';
        $city = '';
        foreach ($parts as $i => $part) {
            $plain = mb_strtolower(preg_replace('/^(г\.?\s*|город\s+)/u', '', $part));
            if (in_array($plain, $federal, true)) {
                return [mb_convert_case($plain, MB_CASE_TITLE), mb_convert_case($plain, MB_CASE_TITLE)];
            }
            if ($region === '' && preg_match('/(край|обл\.?|область|автономный округ|автономная область|\bао)$|^(республика|респ\.)/iu', $part)) {
                $region = $part;

                continue;
            }
            if ($city === '' && ($region !== '' || $i === 0)
                && ! preg_match('/^(ул|улица|пр|пр-кт|проспект|пер|переулок|б-р|бульвар|ш|шоссе|д|дом|кв|проезд|наб|пл|площадь|мкр|микрорайон|тер|стр|корп|р-н|район)\b\.?/iu', $part)
                && ! preg_match('/\d/u', $part)) {
                $city = trim(preg_replace('/^(г\.?|город|пгт\.?|пос\.?|посёлок|поселок|с\.|село|ст-ца|станица|рп\.?|дп\.?)\s*/u', '', $part));
            }
            if ($city !== '' && $region !== '') {
                break;
            }
        }

        return [$city, $region];
    }

    // ------------------------------------------------------------ расчёт

    /** @param array{weight: int, length: int, width: int, height: int} $parcel */
    private function posting(array $parcel, int $declaredKopecks, ?string $cutoff): array
    {
        return [
            'request_id' => 1,
            'shipment_method_id' => (int) $this->origin(),
            'cutoff_at' => $cutoff,
            'declared_value' => ['amount' => number_format(max($declaredKopecks, 100) / 100, 2, '.', ''), 'currency_code' => 'RUB'],
            'dimensions' => ['weight_g' => $parcel['weight'], 'length_mm' => $parcel['length'] * 10,
                'width_mm' => $parcel['width'] * 10, 'height_mm' => $parcel['height'] * 10],
        ];
    }

    /**
     * Когда планируем сдать посылку в пункт отгрузки. Ozon требует момент в
     * будущем («Плановый момент отгрузки не может быть в прошлом»): до 10:00
     * по Москве — сегодня в 12:00, позже — завтра в 12:00.
     */
    public static function plannedCutoff(): string
    {
        $now = Carbon::now('Europe/Moscow');
        $at = $now->copy()->setTime(12, 0);
        if ($now->gte($at->copy()->subHours(2))) {
            $at->addDay();
        }

        return $at->utc()->format('Y-m-d\\TH:i:s\\Z');
    }

    /** Срок сдачи для пункта — его же ждут расчёт и создание. Свой план, если Ozon не дал момента в будущем. */
    private function cutoff(string $pointId, array $parcel, int $declared): string
    {
        $planned = self::plannedCutoff();
        $body = $this->ok($this->post('/v1/delivery-point/check-availability', [
            'delivery_point_ids' => [(int) $pointId],
            'shipment_method_id' => (int) $this->origin(),
            'postings' => [$this->posting($parcel, $declared, $planned)],
        ]), 'доступность пункта')->json('results');
        $item = is_array($body) ? ($body[0] ?? null) : null;
        if ($error = $this->itemError($item)) {
            throw ValidationException::withMessages(['delivery_quote' => 'Этот пункт Ozon не примет посылку: '.mb_substr($error, 0, 150).'. Выберите другой.']);
        }
        $returned = $item['cutoff_at'] ?? null;
        $time = is_string($returned) ? strtotime($returned) : false;

        return $time !== false && $time > Carbon::now()->timestamp + 600 ? $returned : $planned;
    }

    public function tariffs(array $parcel, array $point, array $context): array
    {
        $declared = (int) ($context['assessed'] ?? 0);
        $cutoff = $this->cutoff((string) $point['id'], $parcel, $declared);
        $phone = Phone::format((string) ($context['phone'] ?? ''))
            ?? Phone::format((string) (Setting::get('delivery_sender_phone') ?: SellerProfile::current()?->phone));
        $body = $this->ok($this->post('/v1/order/checkout', array_filter([
            'recipient' => $phone ? ['phone_number' => $phone] : null,
            'postings' => [$this->posting($parcel, $declared, $cutoff)],
            'delivery' => ['delivery_point' => ['delivery_point_id' => (int) $point['id']]],
        ])), 'расчёт')->json('results');
        $item = is_array($body) ? ($body[0] ?? null) : null;
        if ($error = $this->itemError($item)) {
            throw ValidationException::withMessages(['delivery_quote' => 'Ozon не рассчитал доставку: '.mb_substr($error, 0, 150)]);
        }
        $cost = $this->kopecks($item['posting']['estimated_delivery_cost']['amount'] ?? null);
        if ($cost === null) {
            return [];
        }
        $insurance = $this->kopecks($item['posting']['estimated_insurance_cost']['amount'] ?? 0) ?? 0;
        $days = is_int($item['posting']['estimated_delivery_days'] ?? null) ? $item['posting']['estimated_delivery_days'] : null;

        return [['tariff_id' => 'pvz', 'tariff_name' => 'До пункта выдачи Ozon', 'cost' => $cost + $insurance,
            'days_min' => $days, 'days_max' => $days, 'extra' => ['cutoff_at' => $cutoff]]];
    }

    // --------------------------------------------------------- отправление

    /** Ключ повтора — один и тот же для заказа при любом числе попыток. */
    public static function idempotencyKey(Order $order): string
    {
        $hash = sha1('livsi.shop/ozon/'.$order->id.'/'.$order->shipmentRef());

        return sprintf('%s-%s-5%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80).substr($hash, 18, 2), substr($hash, 20, 12));
    }

    public function createShipment(Order $order, array $sender): array
    {
        $order->loadMissing('items');
        $parcel = $this->parcel($order);
        $declared = (int) $order->items_total;
        $cutoff = $this->cutoff((string) $order->delivery_point_id, $parcel, $declared);
        $names = $order->items->map(fn ($item): string => $item->label().' ×'.$item->quantity)->implode('; ');
        $posting = $this->posting($parcel, $declared, $cutoff) + [
            'posting_external_id' => $order->shipmentRef().'-1',
            'description' => mb_substr('Косметика LIVSI: '.$names, 0, 500),
        ];
        $response = $this->post('/v1/order/create', [
            'order_external_id' => $order->shipmentRef(),
            'recipient' => ['phone_number' => $this->phone($order->customer_phone), 'full_name' => mb_substr($order->customer_name, 0, 200)],
            'delivery' => ['delivery_point' => ['delivery_point_id' => (int) $order->delivery_point_id]],
            'postings' => [$posting],
        ], 40, ['Idempotency-Key' => self::idempotencyKey($order)]);
        $body = $this->ok($response, 'создание заказа')->json();
        $number = $body['order_number'] ?? null;
        if (! is_scalar($number) || (string) $number === '') {
            throw new RuntimeException('Ozon не вернул номер заказа.');
        }
        $postings = array_values(array_filter(array_map(fn ($p) => is_array($p) ? (string) ($p['posting_number'] ?? '') : '',
            (array) ($body['postings'] ?? []))));

        // Отправление создают, когда посылка собрана, — сразу подтверждаем
        // отгрузку: Ozon проверит баланс и начнёт готовить этикетку. Не вышло
        // (например, мало денег) — заказ всё равно создан, подтверждение
        // повторится при печати этикетки.
        $approveError = null;
        foreach ($postings as $posting) {
            try {
                $this->approve($posting);
            } catch (Throwable $exception) {
                $approveError = $exception->getMessage();
            }
        }

        return ['external_id' => (string) $number, 'tracking' => $postings[0] ?? null,
            'status_code' => $approveError ? 'created' : 'forming',
            'status_name' => $approveError
                ? 'Создано в Ozon, но не подтверждено: '.mb_substr($approveError, 0, 180)
                : 'Создано в Ozon, готовится этикетка',
            'data' => array_filter(['number' => (string) $number, 'postings' => $postings, 'cutoff_at' => $cutoff,
                'approve_error' => $approveError ? mb_substr($approveError, 0, 255) : null])];
    }

    private function approve(string $posting): void
    {
        $this->ok($this->post('/v1/posting/approve', ['posting_number' => $posting], 20), 'подтверждение отгрузки');
    }

    /**
     * Ответ на создание потерялся: повторяем тот же запрос с тем же ключом
     * повтора — Ozon вернёт уже созданный заказ, а не заведёт второй.
     */
    public function findShipment(Order $order): ?array
    {
        return $this->createShipment($order, []);
    }

    /** @return list<string> */
    private function postings(Order $order): array
    {
        $postings = array_values(array_filter((array) ($order->shipment_data['postings'] ?? []), 'is_string'));
        if (! $postings) {
            throw new RuntimeException('Ozon ещё не сообщил номер отправления.');
        }

        return $postings;
    }

    /** @return array<string, mixed> отправление из posting/info */
    private function postingInfo(string $posting): array
    {
        $found = collect((array) $this->ok($this->post('/v1/posting/info', ['posting_numbers' => [$posting]]), 'статус отправления')
            ->json('postings'))->first(fn ($p) => is_array($p) && ($p['posting_number'] ?? null) === $posting);
        if (! is_array($found)) {
            throw new RuntimeException('Ozon не нашёл отправление '.$posting.'.');
        }

        return $found;
    }

    /** Статусы из документации: название для людей и статус доставки сайта. */
    private const STATUSES = [
        'unknown' => ['Статус Ozon не определён', null],
        'created' => ['Создано в Ozon, ждёт подтверждения', null],
        'forming' => ['Ozon подтверждает отправление', null],
        'forming_failed' => ['Ozon не подтвердил отправление', null],
        'ready_for_shipping' => ['Готово к отгрузке — можно сдавать в пункт', null],
        'in_container' => ['Добавлено в грузоместо', null],
        'acceptance_in_progress' => ['Принимается в пункте отгрузки', Fulfillment::SHIPPED],
        'on_way' => ['В пути', Fulfillment::SHIPPED],
        'not_accepted_to_delivery' => ['Не принято в сортировочном центре', null],
        'in_delivery_point' => ['В пункте выдачи', Fulfillment::AT_PICKUP],
        'in_courier_service' => ['У курьера Ozon', Fulfillment::SHIPPED],
        'delivered' => ['Получено', Fulfillment::DELIVERED],
        'canceled' => ['Отменено', null],
    ];

    public function shipmentInfo(Order $order): array
    {
        $posting = $this->postings($order)[0];
        $info = $this->postingInfo($posting);
        $status = (string) ($info['status'] ?? '');
        [$name, $fulfillment] = self::STATUSES[$status] ?? [$status !== '' ? 'Ozon: '.$status : null, null];
        if ($status === 'in_delivery_point' && is_string($info['storage_expires_at'] ?? null)) {
            $name .= ', хранится до '.Carbon::parse($info['storage_expires_at'])->timezone('Europe/Moscow')->format('d.m');
        }

        return [
            'tracking' => $posting,
            'status_code' => $status !== '' ? $status : null,
            'status_name' => $name,
            'fulfillment' => $fulfillment,
            'data' => array_filter([
                'status_changed_at' => $info['status_changed_at'] ?? null,
                'delivery_at' => $info['delivery_at'] ?? null,
                'storage_expires_at' => $info['storage_expires_at'] ?? null,
            ]),
        ];
    }

    /**
     * Этикетка Ozon. Её выдают только подтверждённому отправлению
     * (ready_for_shipping): если ещё не подтверждено — подтверждаем и
     * коротко ждём, пока Ozon её подготовит. Размер у Ozon один, свой.
     */
    public function label(Order $order, string $size = '75x120'): string
    {
        $posting = $this->postings($order)[0];
        $status = (string) ($this->postingInfo($posting)['status'] ?? '');
        if ($status === 'canceled') {
            throw ValidationException::withMessages(['shipment' => 'Отправление Ozon отменено — этикетка не нужна.']);
        }
        if (in_array($status, ['created', 'forming_failed'], true)) {
            try {
                $this->approve($posting);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['shipment' => 'Ozon не подтвердил отправление: '
                    .mb_substr(preg_replace('/^.*?HTTP \d+ /u', '', $exception->getMessage()), 0, 200)]);
            }
            $status = 'forming';
        }
        for ($try = 0; $status === 'forming' && $try < 6; $try++) {
            Sleep::for(2)->seconds();
            $status = (string) ($this->postingInfo($posting)['status'] ?? '');
        }
        if ($status === 'forming') {
            throw ValidationException::withMessages(['shipment' => 'Ozon ещё готовит этикетку — повторите печать через минуту.']);
        }
        if ($status === 'forming_failed') {
            throw ValidationException::withMessages(['shipment' => 'Ozon не подтвердил отправление. Проверьте баланс в кабинете Ozon Доставки и повторите.']);
        }

        $response = $this->ok($this->post('/v1/posting/label', ['posting_number' => $posting], 40,
            ['Accept' => 'application/pdf, application/json;q=0.5, */*;q=0.1']), 'этикетка');
        if (str_starts_with($response->body(), '%PDF')) {
            return $response->body();
        }
        // На случай, если Ozon завернёт файл в JSON
        $json = (array) $response->json();
        foreach (['file', 'content', 'label', 'file_content', 'pdf'] as $key) {
            $value = $json[$key] ?? null;
            if (is_string($value) && ($pdf = base64_decode($value, true)) && str_starts_with($pdf, '%PDF')) {
                return $pdf;
            }
        }

        throw new RuntimeException('Ozon прислал этикетку не в PDF — её можно скачать в кабинете Ozon Доставки.');
    }

    /** Отмена запускается у каждого отправления заказа; итог — статус canceled. */
    public function cancel(Order $order): void
    {
        foreach ($this->postings($order) as $posting) {
            $this->ok($this->post('/v1/posting/cancel', ['posting_number' => $posting]), 'отмена');
        }
    }
}
