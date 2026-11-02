<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiShipClient
{
    public function canDeliverToOzonClient(string $phone): bool
    {
        $this->guard('ozondel');
        $body = Http::acceptJson()
            ->withHeaders(['Authorization' => (string) config('livsi.apiship.token')])
            ->withOptions(filled(config('livsi.apiship.source_ip')) ? [
                'curl' => [CURLOPT_INTERFACE => (string) config('livsi.apiship.source_ip')],
            ] : [])
            ->connectTimeout(2)->timeout(12)
            ->post(rtrim((string) config('livsi.apiship.api_url'), '/').'/ozondel/check-client', [
                'phoneNumber' => $phone,
                ...(filled(config('livsi.apiship.ozon_connection_id'))
                    ? ['providerConnectId' => (int) config('livsi.apiship.ozon_connection_id')] : []),
            ])->throw()->json();

        if (! is_bool($body['canBeDelivered'] ?? null)) {
            throw new RuntimeException('Ozon returned an invalid eligibility response.');
        }

        return $body['canBeDelivered'];
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function calculate(array $parameters): array
    {
        $this->guard($parameters['providerKeys'][0]);

        return Http::acceptJson()
            ->withHeaders(['Authorization' => (string) config('livsi.apiship.token')])
            ->withOptions(filled(config('livsi.apiship.source_ip')) ? [
                'curl' => [CURLOPT_INTERFACE => (string) config('livsi.apiship.source_ip')],
            ] : [])
            ->connectTimeout(2)->timeout(12)
            ->post(rtrim((string) config('livsi.apiship.api_url'), '/').'/calculator', $parameters)
            ->throw()->json();
    }

    public function isConfigured(): bool
    {
        return filled(config('livsi.apiship.token'));
    }

    public function isProviderActive(string $providerKey): bool
    {
        return $this->isConfigured()
            && in_array($providerKey, config('livsi.apiship.active_providers', []), true);
    }

    /** @return array{points: array<int, array<string, mixed>>, total: int} */
    public function points(string $city, string $providerKey, int $offset = 0, int $limit = 50, ?string $region = null): array
    {
        $this->guard($providerKey);

        $filter = ($region ? 'region%'.$this->filterValue($this->regionName($region)) : 'city='.$this->filterValue($city))
            .';providerKey='.$providerKey
            .';countryCode='.config('livsi.delivery.country_code')
            .';availableOperation=[2,3]';

        $body = $this->getPoints($filter, $offset, $limit);

        return [
            'points' => array_values(array_filter(array_map(
                fn (array $point) => $this->eligiblePoint($point, $city, $providerKey, $region)
                    ? $this->publicPoint($point)
                    : null,
                array_filter($body['rows'] ?? [], 'is_array'),
            ))),
            'total' => (int) ($body['meta']['total'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function point(string $id, string $city, string $providerKey, ?string $region = null): ?array
    {
        $this->guard($providerKey);

        $body = $this->getPoints('id='.$id.';providerKey='.$providerKey.';availableOperation=[2,3]', 0, 1);
        $point = $body['rows'][0] ?? null;

        if (! is_array($point)
            || (string) ($point['id'] ?? '') !== $id
            || ! $this->eligiblePoint($point, $city, $providerKey, $region)) {
            return null;
        }

        return $this->publicPoint($point);
    }

    /** @return array<string, mixed> */
    private function getPoints(string $filter, int $offset, int $limit): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['Authorization' => (string) config('livsi.apiship.token')])
            ->withOptions(filled(config('livsi.apiship.source_ip')) ? [
                'curl' => [CURLOPT_INTERFACE => (string) config('livsi.apiship.source_ip')],
            ] : [])
            ->connectTimeout(2)
            ->timeout(5)
            ->get(rtrim((string) config('livsi.apiship.api_url'), '/').'/lists/points', [
                'limit' => $limit,
                'offset' => $offset,
                'filter' => $filter,
                'stateCheckOff' => 0,
            ])
            ->throw();

        return $response->json();
    }

    /** @return array<string, mixed>|null */
    private function publicPoint(array $point): ?array
    {
        if (($point['enabled'] ?? true) === false || ! isset($point['id'], $point['address'])) {
            return null;
        }

        return [
            'id' => (string) $point['id'],
            'name' => (string) ($point['name'] ?? ''),
            'address' => (string) $point['address'],
            'city' => (string) ($point['city'] ?? ''),
            'region' => (string) ($point['region'] ?? ''),
            'provider' => (string) ($point['providerKey'] ?? ''),
            'timetable' => (string) ($point['timetable'] ?? ''),
            'lat' => $point['lat'] ?? null,
            'lng' => $point['lng'] ?? null,
        ];
    }

    private function eligiblePoint(array $point, string $city, string $providerKey, ?string $region = null): bool
    {
        return ($point['providerKey'] ?? null) === $providerKey
            && ($point['countryCode'] ?? null) === config('livsi.delivery.country_code')
            && ($region !== null || $this->cityKey((string) ($point['city'] ?? '')) === $this->cityKey($city))
            && ($region === null || $this->regionKey((string) ($point['region'] ?? '')) === $this->regionKey($region))
            && in_array((int) ($point['availableOperation'] ?? 0), [2, 3], true);
    }

    private function guard(string $providerKey): void
    {
        if (! $this->isProviderActive($providerKey)) {
            throw new RuntimeException('Служба доставки не подключена к ApiShip.');
        }
    }

    private function filterValue(string $value): string
    {
        return str_replace([';', '=', '[', ']'], '', trim($value));
    }

    private function cityKey(string $city): string
    {
        return mb_strtolower(trim(preg_replace('/^(г\\.?|город)\\s+/ui', '', $city) ?? $city));
    }

    private function regionKey(string $region): string
    {
        return mb_strtolower($this->regionName($region));
    }

    private function regionName(string $region): string
    {
        $region = trim($region);

        return trim(preg_replace('/^(республика)\s+|\s+(край|область|республика|автономный округ|автономная область)$/ui', '', $region) ?? $region);
    }
}
