<?php

namespace App\Services;

use App\Delivery\DeliveryGateway;
use App\Models\Cart;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeliveryQuoteService
{
    public function __construct(
        private readonly ApiShipClient $apiShip,
        private readonly PromoService $promos,
        private readonly DeliveryGateway $gateway,
    ) {}

    public function enabled(): bool
    {
        return (bool) Setting::get('delivery_calculation_enabled', false);
    }

    public function testing(): bool
    {
        return (bool) Setting::get('delivery_calculation_test', false)
            && str_starts_with((string) config('livsi.yookassa.secret_key'), 'test_');
    }

    /** @return array{weight: int, length: int, width: int, height: int} */
    public function parcel(Cart $cart): array
    {
        $padding = Setting::get('delivery_padding_mm');
        $tare = Setting::get('delivery_packaging_weight_g');
        if ($padding === null || $tare === null || $padding < 0 || $tare < 0) {
            $this->fail('Не удалось рассчитать доставку. Попробуйте позже.');
        }

        $weight = (int) $tare;
        $length = $height = $width = 0;
        foreach ($cart->items as $item) {
            if ($item->is_gift && ! $this->promos->isGiftUnlocked($cart->subtotal())) {
                continue;
            }
            $variant = $item->variant;
            if ((! $variant->dimensions_verified && ! $this->testing()) || min((int) $variant->weight_g, (int) $variant->length_mm, (int) $variant->width_mm, (int) $variant->height_mm) < 1) {
                $this->fail('Не удалось рассчитать доставку для товаров в корзине. Попробуйте позже.');
            }
            $weight += $variant->weight_g * $item->qty;
            $length = max($length, $variant->length_mm);
            $height = max($height, $variant->height_mm);
            $width += $variant->width_mm * $item->qty;
        }

        return [
            'weight' => $weight,
            'length' => (int) ceil(($length + 2 * $padding) / 10),
            'width' => (int) ceil(($width + 2 * $padding) / 10),
            'height' => (int) ceil(($height + 2 * $padding) / 10),
        ];
    }

    /** @param array<string, mixed> $destination
     * @return array<string, mixed>
     */
    public function calculate(Cart $cart, array $destination): array
    {
        ksort($destination);
        if (! $this->enabled()) {
            $this->fail('Расчёт доставки временно недоступен. Попробуйте позже.');
        }
        $fingerprint = $this->fingerprint($cart);
        $provider = DeliverySelection::providerKey($destination['delivery_point_method']);
        if (! in_array($provider, ['cdek', 'yataxi', 'ozondel'], true)) {
            $this->fail($provider === 'ozonlog'
                ? 'Расчёт доставки Ozon временно недоступен. Выберите пункт выдачи другой службы.'
                : 'Расчёт для этой службы недоступен. Выберите пункт выдачи другой службы.');
        }
        $point = $this->gateway->point($destination['delivery_point_id'], $destination['delivery_city'], $provider, $destination['delivery_region'] ?? null);
        if (! $point) {
            $this->fail('Этот пункт выдачи недоступен. Выберите другой.');
        }
        if ($this->gateway->isDirect($provider)) {
            return $this->calculateDirect($cart, $destination, $provider, $point, $fingerprint);
        }
        $address = Setting::get('delivery_origin_address');
        $pickup = $provider === 'ozondel' ? 2 : (int) Setting::get('delivery_pickup_type');
        if (! $address || ! in_array($pickup, [1, 2], true)) {
            $this->fail('Не удалось рассчитать доставку. Попробуйте позже.');
        }
        $pointInId = Setting::get('delivery_origin_points', [])[$provider] ?? null;
        if ($provider !== 'ozondel' && $pickup === 2 && (! is_scalar($pointInId) || ! ctype_digit((string) $pointInId))) {
            $this->fail('Расчёт доставки для этой службы временно недоступен. Выберите пункт выдачи другой службы.');
        }
        $parcel = $this->parcel($cart);
        $body = $this->apiShip->calculate(array_merge($parcel, [
            'from' => ['countryCode' => config('livsi.delivery.country_code'), 'addressString' => $address],
            'to' => ['countryCode' => config('livsi.delivery.country_code'), 'city' => $point['city'], 'region' => $point['region']],
            'pointOutId' => (int) $point['id'],
            'providerKeys' => [$provider], 'pickupTypes' => [$pickup], 'deliveryTypes' => [2],
            'assessedCost' => $cart->subtotal() / 100, 'codCost' => 0, 'includeFees' => $provider !== 'ozondel',
            ...($provider !== 'ozondel' && $pickup === 2 ? ['pointInId' => (int) $pointInId] : []),
        ]));
        $tariffs = [];
        foreach ($body['deliveryToPoint'] ?? [] as $group) {
            if (($group['providerKey'] ?? null) !== $provider) {
                continue;
            }
            foreach ($group['tariffs'] ?? [] as $tariff) {
                $cost = $this->tariffCost($tariff, $provider);
                if (! isset($tariff['tariffId']) || $cost === null
                    || ! in_array($pickup, $tariff['pickupTypes'] ?? [], true)
                    || ! in_array(2, $tariff['deliveryTypes'] ?? [], true)
                    || ! isset($tariff['pointIds']) || ! in_array((string) $point['id'], array_map('strval', $tariff['pointIds']), true)) {
                    continue;
                }
                $tariffs[] = [...$tariff, 'total_cost' => $cost];
            }
        }
        if (! $tariffs) {
            $this->fail('Для этого пункта нет доступного тарифа. Выберите другой пункт выдачи.');
        }
        usort($tariffs, fn (array $a, array $b): int => $a['total_cost'] <=> $b['total_cost']);
        $tariff = $tariffs[0];
        $cost = $tariff['total_cost'];

        return [
            'test' => $this->testing(),
            'provider' => $provider, 'tariff_id' => $tariff['tariffId'], 'tariff_name' => (string) ($tariff['tariffName'] ?? ''),
            'carrier_cost' => $cost,
            'price' => $this->customerPrice($cart, $cost),
            'days_min' => $tariff['calendarDaysMin'] ?? null, 'days_max' => $tariff['calendarDaysMax'] ?? null,
            'parcel' => $parcel, 'destination' => $destination, 'point' => $point,
            'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(10)->timestamp,
        ];
    }

    /**
     * Цена доставки для покупателя: 0 при сумме выше порога бесплатной доставки
     * или с промокодом на бесплатную доставку (он лежит в сессии оформления).
     */
    private function customerPrice(Cart $cart, int $cost): int
    {
        $promoFree = (bool) (session('checkout_promo.free_delivery') ?? false);

        return $promoFree || $this->promos->remainingToFreeShipping($cart->subtotal()) === 0 ? 0 : $cost;
    }

    /** @param array<string, mixed> $quote */
    public function token(array $quote, string $sessionId): string
    {
        return Crypt::encryptString(json_encode(['quote' => $quote, 'session' => hash('sha256', $sessionId)], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $destination
     * @return array<string, mixed>
     */
    public function accept(string $token, Cart $cart, array $destination, string $sessionId): array
    {
        ksort($destination);
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            $quote = $data['quote'];
            if (! hash_equals($data['session'], hash('sha256', $sessionId))
                || $quote['expires_at'] <= now()->timestamp
                || $quote['destination'] !== $destination
                || $quote['fingerprint'] !== $this->fingerprint($cart)
                || ($quote['direct'] ?? false) !== $this->gateway->isDirect($quote['provider'])
                || ! $this->gateway->isProviderActive($quote['provider'])
                || ! $this->enabled()) {
                $this->fail('Расчёт доставки устарел. Рассчитайте стоимость ещё раз.');
            }

            return $quote;
        } catch (Throwable $exception) {
            $this->fail('Расчёт доставки устарел. Рассчитайте стоимость ещё раз.');
        }
    }

    private function fingerprint(Cart $cart): string
    {
        $cart->load('items.variant.product');

        return hash('sha256', json_encode([
            $cart->id, $cart->items->sortBy('id')->map(fn ($item): array => [
                $item->product_variant_id, $item->qty, $item->is_gift, $item->variant->price,
                $item->variant->weight_g, $item->variant->length_mm, $item->variant->width_mm, $item->variant->height_mm,
                $item->variant->dimensions_verified,
            ])->values()->all(),
            $this->promos->remainingToFreeShipping($cart->subtotal()), $this->promos->isGiftUnlocked($cart->subtotal()),
            session('checkout_promo.code'),
            Setting::get('delivery_padding_mm'), Setting::get('delivery_packaging_weight_g'),
            Setting::get('delivery_origin_address'), Setting::get('delivery_pickup_type'), Setting::get('delivery_origin_points'),
            Setting::get('delivery_calculation_test', false), $this->testing(), config('livsi.apiship.ozon_connection_id'),
            config('livsi.delivery.drivers'), Setting::get('delivery_drivers', []), Setting::get('delivery_direct_origin', []),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Расчёт напрямую у службы.
     *
     * Служба сама знает свои тарифы до пункта; берём самый дешёвый, как и
     * при ApiShip. Ответ того же вида, что у расчёта через ApiShip, плюс
     * пометка direct — по ней оформление и отправление идут напрямую.
     *
     * @param  array<string, mixed>  $destination
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>
     */
    private function calculateDirect(Cart $cart, array $destination, string $provider, array $point, string $fingerprint): array
    {
        $parcel = $this->parcel($cart);
        $items = [];
        foreach ($cart->items as $item) {
            if ($item->is_gift && ! $this->promos->isGiftUnlocked($cart->subtotal())) {
                continue;
            }
            $items[] = ['sku' => $item->variant->sku, 'quantity' => (int) $item->qty, 'price' => (int) $item->variant->price];
        }
        $tariffs = array_values(array_filter(
            $this->gateway->carrier($provider)->tariffs($parcel, $point, ['assessed' => $cart->subtotal(), 'items' => $items]),
            fn (array $tariff): bool => is_int($tariff['cost'] ?? null) && $tariff['cost'] >= 0 && filled($tariff['tariff_id'] ?? null),
        ));
        if (! $tariffs) {
            $this->fail('Для этого пункта нет доступного тарифа. Выберите другой пункт выдачи.');
        }
        usort($tariffs, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
        $tariff = $tariffs[0];

        return [
            'test' => $this->testing(), 'direct' => true,
            'provider' => $provider, 'tariff_id' => (string) $tariff['tariff_id'], 'tariff_name' => (string) ($tariff['tariff_name'] ?? ''),
            'carrier_cost' => $tariff['cost'],
            'price' => $this->customerPrice($cart, $tariff['cost']),
            'days_min' => $tariff['days_min'] ?? null, 'days_max' => $tariff['days_max'] ?? null,
            'extra' => $tariff['extra'] ?? [],
            'parcel' => $parcel, 'destination' => $destination, 'point' => $point,
            'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(10)->timestamp,
        ];
    }

    /** @param array<string, mixed> $tariff */
    private function tariffCost(array $tariff, string $provider): ?int
    {
        $cost = $this->money($tariff['deliveryCost'] ?? null);
        if ($cost === null || ($tariff['feesIncluded'] ?? null) === true) {
            return $cost;
        }
        if ($provider !== 'ozondel') {
            return null;
        }

        // Ozon is requested without fees; prepaid orders have no cash collection fee.
        $insurance = $this->money($tariff['insuranceFee'] ?? null);
        $cashFee = $this->money($tariff['cashServiceFee'] ?? 0);

        return $insurance !== null && $cashFee !== null ? $cost + $insurance + $cashFee : null;
    }

    private function money(mixed $value): ?int
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0
            ? (int) round((float) $value * 100) : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['delivery_quote' => $message]);
    }
}
