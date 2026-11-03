<?php

namespace App\Delivery;

use App\Delivery\Carriers\CdekCarrier;
use App\Delivery\Carriers\OzonCarrier;
use App\Delivery\Carriers\YandexCarrier;
use App\Models\DeliveryPoint;
use App\Services\ApiShipClient;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Развилка: через ApiShip или напрямую — для каждой службы отдельно.
 *
 * Переключатель живёт в .env: DELIVERY_CDEK, DELIVERY_YANDEX, DELIVERY_OZON
 * со значением apiship (как было) или direct. Пока стоит apiship, сайт
 * работает ровно как до установки. Переключили одну службу — проверили —
 * следующую. Откат — вернуть apiship и сбросить кэш настроек.
 *
 * Снаружи развилка выглядит как ApiShipClient: те же points() и point(),
 * поэтому карта и расчёт не знают, через кого идёт служба.
 */
class DeliveryGateway
{
    public const PROVIDERS = ['cdek', 'yataxi', 'ozondel'];

    public function __construct(private readonly ApiShipClient $apiShip) {}

    public function isDirect(?string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && DeliverySettings::driver($provider) === 'direct';
    }

    public function carrier(string $provider): Carrier
    {
        return app(match ($provider) {
            'cdek' => CdekCarrier::class,
            'yataxi' => YandexCarrier::class,
            'ozondel' => OzonCarrier::class,
            default => throw new InvalidArgumentException('Неизвестная служба доставки: '.$provider),
        });
    }

    /** @return list<Carrier> */
    public function directCarriers(): array
    {
        return array_values(array_map(
            fn (string $provider): Carrier => $this->carrier($provider),
            array_filter(self::PROVIDERS, fn (string $provider): bool => $this->isDirect($provider)),
        ));
    }

    /**
     * Служба доступна покупателю.
     *
     * Напрямую — когда заданы ключи и пункты уже загружены: переключатель,
     * поставленный до первой синхронизации, не покажет пустую карту, служба
     * просто не появится в списке.
     */
    public function isProviderActive(string $provider): bool
    {
        if (! $this->isDirect($provider)) {
            return $this->apiShip->isProviderActive($provider);
        }

        return $this->carrier($provider)->isConfigured()
            && Cache::remember('delivery-points-ready:'.$provider, now()->addMinutes(10),
                fn (): bool => DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)->exists());
    }

    /** @return array{points: array<int, array<string, mixed>>, total: int} */
    public function points(string $city, string $provider, int $offset = 0, int $limit = 50, ?string $region = null): array
    {
        $hidden = DeliverySettings::hiddenPoints($provider);
        if (! $this->isDirect($provider)) {
            $result = $this->apiShip->points($city, $provider, $offset, $limit, $region);
            if ($hidden) {
                $before = count($result['points']);
                $result['points'] = array_values(array_filter($result['points'], fn (array $point): bool => ! in_array((string) ($point['id'] ?? ''), $hidden, true)));
                $result['total'] = max(0, (int) $result['total'] - ($before - count($result['points'])));
            }

            return $result;
        }

        $query = $this->pointsQuery($provider, $city, $region)->when($hidden, fn ($q) => $q->whereNotIn('external_id', $hidden));

        return [
            'points' => $query->clone()->orderBy('id')->offset($offset)->limit($limit)->get()
                ->map(fn (DeliveryPoint $point): array => $point->toPublic())->all(),
            'total' => $query->count(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function point(string $id, string $city, string $provider, ?string $region = null): ?array
    {
        if (DeliverySettings::isHidden($provider, $id)) {
            return null;
        }
        if (! $this->isDirect($provider)) {
            return ctype_digit($id) ? $this->apiShip->point($id, $city, $provider, $region) : null;
        }

        return $this->pointsQuery($provider, $city, $region)->where('external_id', $id)->first()?->toPublic();
    }

    /** Пункт по номеру без проверки города — для заказа, который уже оформлен. */
    public function storedPoint(string $provider, string $id): ?DeliveryPoint
    {
        return DeliveryPoint::query()->where('provider', $provider)->where('external_id', $id)->first();
    }

    private function pointsQuery(string $provider, string $city, ?string $region)
    {
        $query = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true);

        return filled($region)
            ? $query->where('region_key', PointNames::regionKey($region))
            : $query->where('city_key', PointNames::cityKey($city));
    }
}
