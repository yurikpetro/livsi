<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CityGeocodeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $forward = $request->has('city');
        $data = $request->validate($forward ? [
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'region' => ['required', 'string', 'min:2', 'max:120'],
        ] : [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $key = (string) config('livsi.delivery.geocoder_api_key');

        if ($key === '') {
            return response()->json(['message' => 'Определение города временно недоступно.'], 503);
        }

        try {
            $query = [
                'apikey' => $key,
                'geocode' => $forward
                    ? implode(', ', [$data['city'], $data['region'], 'Россия'])
                    : (float) $data['longitude'].','.(float) $data['latitude'],
                'lang' => 'ru_RU',
                'results' => 1,
                'format' => 'json',
            ];

            if (! $forward) {
                $query['kind'] = 'locality';
            }

            $response = Http::timeout(4)->get('https://geocode-maps.yandex.ru/v1/', $query)->throw();

            $geoObject = $response->json('response.GeoObjectCollection.featureMember.0.GeoObject');
            $metadata = $geoObject['metaDataProperty']['GeocoderMetaData'] ?? null;

            if (! is_array($metadata) || ($metadata['kind'] ?? null) !== 'locality') {
                return response()->json(['message' => 'Город не определён. Выберите его вручную.'], 404);
            }

            $address = $metadata['Address'] ?? [];

            if (! in_array(strtoupper((string) ($address['country_code'] ?? '')), ['RU', 'RUS'], true)) {
                return response()->json(['message' => 'Город не определён. Выберите его вручную.'], 404);
            }

            $city = null;
            $region = '';

            foreach ($address['Components'] ?? [] as $component) {
                if (($component['kind'] ?? '') === 'locality') {
                    $city = $component['name'] ?? null;
                }

                if (($component['kind'] ?? '') === 'province'
                    && preg_match('/край|область|республика|автономный округ|^Москва$|^Санкт-Петербург$|^Севастополь$/ui', $component['name'] ?? '') === 1
                    && ! str_contains($component['name'] ?? '', 'федеральный округ')) {
                    $region = $component['name'];
                }
            }

            if ($forward) {
                $provinces = array_values(array_filter($address['Components'] ?? [],
                    fn (array $component) => ($component['kind'] ?? '') === 'province'));
                $cityMatches = is_string($city)
                    && $this->locationKey($city) === $this->locationKey($data['city']);
                $regionMatches = count(array_filter($provinces,
                    fn (array $component) => $this->locationKey((string) ($component['name'] ?? ''))
                        === $this->locationKey($data['region']))) > 0;
                $position = preg_split('/\s+/', trim((string) ($geoObject['Point']['pos'] ?? '')));

                if (! $cityMatches || ! $regionMatches || count($position) !== 2
                    || ! is_numeric($position[0]) || ! is_numeric($position[1])
                    || abs((float) $position[0]) > 180 || abs((float) $position[1]) > 90) {
                    return response()->json(['message' => 'Координаты населённого пункта не найдены.'], 404);
                }

                return response()->json(['latitude' => (float) $position[1], 'longitude' => (float) $position[0]]);
            }

            return is_string($city) && $city !== ''
                ? response()->json(['city' => $city, 'region' => $region])
                : response()->json(['message' => 'Город не определён. Выберите его вручную.'], 404);
        } catch (Throwable $exception) {
            Log::warning('Не удалось определить город через Геокодер Яндекса.', [
                'error_type' => $exception::class,
            ]);

            return response()->json(['message' => 'Определение города временно недоступно.'], 503);
        }
    }

    private function locationKey(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return trim(preg_replace('/^(г\.|город|село|деревня|пос[её]лок|станица|хутор|республика)\s+|\s+(край|область|республика)$/ui', '', $name) ?? $name);
    }
}
