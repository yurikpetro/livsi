<?php

namespace App\Services;

use App\Delivery\DirectShipmentService;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShipmentService
{
    public function __construct(private readonly ApiShipClient $apiShip) {}

    /** @return array{name: ?string, phone: ?string, email: ?string} */
    public function senderDefaults(): array
    {
        $seller = SellerProfile::current();

        return ['name' => $seller?->legal_name, 'phone' => Setting::get('delivery_sender_phone') ?: $seller?->phone, 'email' => $seller?->email];
    }

    /** @param array{name?: mixed, phone?: mixed, email?: mixed} $sender */
    public function create(Order $record, array $sender): Order
    {
        $this->authorize();

        return $this->createShipment($record, $sender);
    }

    public function createAutomatically(Order $record): Order
    {
        $record = $record->fresh();
        if (! $record->shipment_automatic) {
            $this->fail('Для этого заказа автоматическая отправка не включена.');
        }

        return $this->createShipment($record, $this->senderDefaults());
    }

    /** @param array<string, mixed> $sender */
    private function createShipment(Order $record, array $sender): Order
    {
        if (DirectShipmentService::isDirectOrder($record)) {
            return app(DirectShipmentService::class)->create($record, $sender);
        }
        [$order, $payload] = DB::transaction(function () use ($record, $sender): array {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);
            if (! $order->isPaid()) {
                $this->fail('Отправление можно создать только для оплаченного заказа.');
            }
            if ($order->apiship_order_id) {
                return [$order, null];
            }
            if (in_array($order->shipment_state, ['creating', 'unknown'], true)) {
                $this->fail('Запрос уже отправлен. Статус проверяется без создания повторной заявки.');
            }
            $payload = $this->payload($order, $sender);
            $order->forceFill([
                'shipment_state' => 'creating', 'shipment_requested_at' => now(),
                'shipment_error' => null, 'shipment_sender' => $payload['sender'],
            ])->save();

            return [$order, $payload];
        });

        if ($payload === null) {
            return $order;
        }
        try {
            $response = $this->apiShip->createShipment($payload);
        } catch (Throwable $exception) {
            $this->unknown($order);
        }
        if ($response->successful() && $this->validId($response->json('orderId'))) {
            $order->forceFill([
                'apiship_order_id' => (string) $response->json('orderId'), 'shipment_state' => 'created',
                'shipment_status_name' => 'Принято ApiShip', 'shipment_created_at' => now(), 'shipment_error' => null,
            ])->save();

            return $order;
        }
        if (in_array($response->status(), [400, 401, 403, 422], true)) {
            $message = 'ApiShip отклонил заявку (HTTP '.$response->status().'). Проверьте данные отправителя и настройки службы доставки.';
            $order->forceFill(['shipment_state' => 'failed', 'shipment_error' => $message])->save();
            $this->fail($message);
        }
        $this->unknown($order);
    }

    public function refresh(Order $record): Order
    {
        $this->authorize();

        return $this->refreshShipment($record);
    }

    public function refreshAutomatically(Order $record): Order
    {
        $record = $record->fresh();
        if (! $record->shipment_automatic) {
            $this->fail('Для этого заказа автоматическая отправка не включена.');
        }

        return $this->refreshShipment($record);
    }

    private function refreshShipment(Order $record): Order
    {
        if (DirectShipmentService::isDirectOrder($record)) {
            return app(DirectShipmentService::class)->refresh($record);
        }
        $order = $record->fresh();
        if (! $order->shipment_requested_at && ! $order->apiship_order_id) {
            $this->fail('Заявка на отправление ещё не отправлялась.');
        }
        try {
            $response = $this->apiShip->shipmentStatus($order->number);
        } catch (Throwable $exception) {
            $this->fail('Не удалось проверить отправление. Повторите проверку позже; новая заявка не отправлялась.');
        }
        $info = $response->json('orderInfo');
        if (! $response->successful() || ! is_array($info) || ! $this->validId($info['orderId'] ?? null)
            || ($info['clientNumber'] ?? null) !== $order->number
            || ($info['providerKey'] ?? null) !== ($order->delivery_quote['provider'] ?? null)
            || ($order->apiship_order_id && (string) $info['orderId'] !== $order->apiship_order_id)) {
            $this->fail('ApiShip пока не подтвердил отправление. Повторите проверку позже. Если результат не появится, сверьте заявку в кабинете ApiShip по номеру '.$order->number.'.');
        }
        $status = $response->json('status') ?? $response->json('statuses.0');
        $name = is_array($status) && is_string($status['name'] ?? null) ? $status['name'] : 'Принято ApiShip';
        $tracking = $info['providerNumber'] ?? null;
        $order->forceFill([
            'apiship_order_id' => (string) $info['orderId'], 'shipment_state' => 'created',
            'shipment_status_name' => mb_substr($name, 0, 255),
            'shipment_provider_status' => is_string($status['key'] ?? null) ? mb_substr($status['key'], 0, 60) : null,
            'shipment_tracking_number' => is_scalar($tracking) ? mb_substr((string) $tracking, 0, 150) : null,
            'shipment_created_at' => $order->shipment_created_at ?? now(), 'shipment_error' => null,
        ])->save();

        return $order;
    }

    /** @param array<string, mixed> $sender
     * @return array<string, mixed>
     */
    private function payload(Order $order, array $sender): array
    {
        $sender = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $sender);
        $sender = Validator::make($sender, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ])->validate();
        $senderPhone = Phone::format($sender['phone']);
        if (! $senderPhone) {
            throw ValidationException::withMessages(['phone' => 'Укажите полный российский телефон отправителя.']);
        }
        $recipientPhone = Phone::format($order->customer_phone);
        if (! $recipientPhone) {
            $this->fail('В заказе отсутствует корректный телефон получателя.');
        }
        $quote = $order->delivery_quote;
        $provider = $quote['provider'] ?? null;
        if (! in_array($provider, ['cdek', 'yataxi', 'ozondel'], true)
            || DeliverySelection::providerKey($order->delivery_method) !== $provider
            || ! $this->apiShip->isProviderActive($provider)) {
            $this->fail('Для заказа нет поддерживаемой рассчитанной доставки.');
        }
        if (! $this->validId($quote['tariff_id'] ?? null) || ! $this->validId($order->delivery_point_id)) {
            $this->fail('В заказе не сохранены тариф или пункт выдачи.');
        }
        $origin = Setting::get('delivery_origin_address');
        $pickup = $provider === 'ozondel' ? 2 : (int) Setting::get('delivery_pickup_type');
        if (! is_string($origin) || blank($origin) || ! in_array($pickup, [1, 2], true)) {
            $this->fail('Заполните адрес и способ отгрузки в настройках сайта.');
        }
        $orderData = ['clientNumber' => $order->number, 'providerKey' => $provider,
            'pickupType' => $pickup, 'deliveryType' => 2, 'tariffId' => (int) $quote['tariff_id'],
            'pointOutId' => (int) $order->delivery_point_id];
        if ($provider === 'ozondel') {
            $connection = config('livsi.apiship.ozon_connection_id');
            if (! $this->validId($connection)) {
                $this->fail('Не настроено подключение Ozon в ApiShip.');
            }
            $orderData['providerConnectId'] = (int) $connection;
        } elseif ($pickup === 2) {
            $pointIn = Setting::get('delivery_origin_points', [])[$provider] ?? null;
            if (! $this->validId($pointIn)) {
                $this->fail('В настройках сайта не указан пункт приёма для выбранной службы.');
            }
            $orderData['pointInId'] = (int) $pointIn;
        }
        $parcel = $this->verifiedParcel($order);
        $items = $order->items->map(fn ($item): array => [
            'articul' => $item->sku, 'description' => $item->label(), 'quantity' => $item->quantity,
            'weight' => (int) $item->variant->weight_g,
            'height' => (int) ceil($item->variant->height_mm / 10),
            'length' => (int) ceil($item->variant->length_mm / 10),
            'width' => (int) ceil($item->variant->width_mm / 10),
            'assessedCost' => $item->unit_price / 100, 'cost' => 0,
        ])->all();
        if ($order->items_total < 1 || $order->items->sum(fn ($item): int => $item->unit_price * $item->quantity) !== $order->items_total) {
            $this->fail('Не удалось определить оценочную стоимость товаров заказа.');
        }
        $seller = SellerProfile::current();
        $senderData = array_filter([
            'contactName' => trim($sender['name']), 'phone' => $senderPhone, 'email' => $sender['email'] ?? null,
            'countryCode' => config('livsi.delivery.country_code'), 'addressString' => $origin,
            'companyName' => $seller?->legal_name, 'companyInn' => $seller?->inn,
        ], fn ($value): bool => filled($value));
        $address = $order->delivery_point_address;
        if (blank($address) || blank($order->delivery_city) || blank($order->customer_name)) {
            $this->fail('В заказе не сохранены адрес ПВЗ, город или имя получателя.');
        }

        return [
            'order' => [...$orderData, ...$parcel],
            'cost' => ['assessedCost' => $order->items_total / 100, 'codCost' => 0, 'deliveryCost' => 0, 'isDeliveryPayedByRecipient' => 0],
            'sender' => $senderData,
            'recipient' => ['contactName' => $order->customer_name, 'phone' => $recipientPhone,
                'email' => $order->customer_email, 'countryCode' => $order->delivery_country_code ?: 'RU',
                'city' => $order->delivery_city, 'addressString' => $address],
            'places' => [['placeNumber' => '1', ...$parcel, 'items' => $items]],
        ];
    }

    /** @return array{weight: int, length: int, width: int, height: int} */
    private function verifiedParcel(Order $order): array
    {
        $order->load('items.variant');
        if ($order->items->isEmpty()) {
            $this->fail('В заказе нет товаров для отправки.');
        }
        $padding = Setting::get('delivery_padding_mm');
        $tare = Setting::get('delivery_packaging_weight_g');
        if (! is_numeric($padding) || ! is_numeric($tare) || $padding < 0 || $tare < 0) {
            $this->fail('Заполните параметры упаковки в настройках сайта.');
        }
        $weight = (int) $tare;
        $length = $width = $height = 0;
        foreach ($order->items as $item) {
            $variant = $item->variant;
            if (! $variant || ! $variant->dimensions_verified || $item->quantity < 1
                || min((int) $variant->weight_g, (int) $variant->length_mm, (int) $variant->width_mm, (int) $variant->height_mm) < 1) {
                $this->fail('Подтвердите вес и габариты товара '.$item->sku.' перед созданием отправления.');
            }
            $weight += $variant->weight_g * $item->quantity;
            $length = max($length, $variant->length_mm);
            $height = max($height, $variant->height_mm);
            $width += $variant->width_mm * $item->quantity;
        }
        $parcel = ['weight' => $weight, 'length' => (int) ceil(($length + 2 * $padding) / 10),
            'width' => (int) ceil(($width + 2 * $padding) / 10), 'height' => (int) ceil(($height + 2 * $padding) / 10)];
        foreach ($parcel as $key => $value) {
            if ($value !== ($order->delivery_quote['parcel'][$key] ?? null)) {
                $this->fail('Размеры товаров или упаковка изменились после расчёта заказа. Сверьте доставку перед созданием отправления.');
            }
        }

        return $parcel;
    }

    private function validId(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && ctype_digit((string) $value) && (int) $value > 0;
    }

    private function authorize(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    private function unknown(Order $order): never
    {
        $message = 'Результат создания не подтверждён. Статус будет проверен автоматически; повторная заявка не отправляется.';
        Order::query()->whereKey($order->id)->whereNull('apiship_order_id')
            ->update(['shipment_state' => 'unknown', 'shipment_error' => $message]);
        $this->fail($message);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['shipment' => $message]);
    }
}
