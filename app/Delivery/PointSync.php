<?php

namespace App\Delivery;

use App\Models\DeliveryPoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Загрузка пунктов выдачи службы в свою базу.
 *
 * Пункты пишутся пачками по 500. Закрытые пункты (которых больше нет в
 * списке службы) выключаются только после полной успешной загрузки — и
 * только если новый список не меньше половины прежнего. Иначе обрыв связи
 * посреди загрузки или пустой ответ службы стёр бы с карты всю страну.
 */
class PointSync
{
    private const CHUNK = 500;

    /** @return array{loaded: int, disabled: int, skipped: int} */
    public function run(Carrier $carrier): array
    {
        $provider = $carrier->key();
        $started = Carbon::now();
        $before = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)->count();
        $buffer = [];
        $loaded = $skipped = 0;
        $seen = [];

        foreach ($carrier->allPoints() as $point) {
            $row = $this->row($provider, $point, $started);
            if ($row === null || isset($seen[$row['external_id']])) {
                $skipped++;

                continue;
            }
            $seen[$row['external_id']] = true;
            $buffer[] = $row;
            if (count($buffer) >= self::CHUNK) {
                $loaded += $this->flush($buffer);
                $buffer = [];
            }
        }
        $loaded += $this->flush($buffer);

        if ($loaded === 0 || ($before > 0 && $loaded < $before / 2)) {
            throw new RuntimeException(sprintf(
                '%s отдал %d пунктов вместо примерно %d. Старый список оставлен как есть.',
                $carrier->title(), $loaded, $before,
            ));
        }

        $disabled = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $started))
            ->update(['is_active' => false, 'updated_at' => Carbon::now()]);
        Cache::forget('delivery-points-ready:'.$provider);

        return ['loaded' => $loaded, 'disabled' => $disabled, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array<string, mixed>|null
     */
    private function row(string $provider, array $point, Carbon $now): ?array
    {
        $id = trim((string) ($point['external_id'] ?? ''));
        $address = trim((string) ($point['address'] ?? ''));
        if ($id === '' || mb_strlen($id) > 64 || $address === '') {
            return null;
        }
        $lat = is_numeric($point['lat'] ?? null) ? (float) $point['lat'] : null;
        $lng = is_numeric($point['lng'] ?? null) ? (float) $point['lng'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }
        $city = mb_substr(trim((string) ($point['city'] ?? '')), 0, 120);
        $region = mb_substr(trim((string) ($point['region'] ?? '')), 0, 120);

        return [
            'provider' => $provider,
            'external_id' => $id,
            'type' => in_array($point['type'] ?? null, ['pvz', 'postamat'], true) ? $point['type'] : 'pvz',
            'name' => mb_substr(trim((string) ($point['name'] ?? '')), 0, 255),
            'address' => mb_substr($address, 0, 500),
            'city' => $city,
            'city_key' => mb_substr(PointNames::cityKey($city), 0, 120),
            'region' => $region,
            'region_key' => mb_substr(PointNames::regionKey($region), 0, 120),
            'lat' => $lat,
            'lng' => $lng,
            'timetable' => mb_substr(trim((string) ($point['timetable'] ?? '')), 0, 500),
            'limits' => is_array($point['limits'] ?? null) ? json_encode($point['limits'], JSON_UNESCAPED_UNICODE) : null,
            'is_active' => true,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        DB::table('delivery_points')->upsert($rows, ['provider', 'external_id'], [
            'type', 'name', 'address', 'city', 'city_key', 'region', 'region_key',
            'lat', 'lng', 'timetable', 'limits', 'is_active', 'synced_at', 'updated_at',
        ]);

        return count($rows);
    }
}
