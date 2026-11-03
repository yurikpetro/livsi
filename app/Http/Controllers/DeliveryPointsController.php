<?php

namespace App\Http\Controllers;

use App\Delivery\DeliveryGateway;
use App\Services\DeliverySelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryPointsController extends Controller
{
    private const PER_PAGE = 1000;

    public function __invoke(Request $request, DeliveryGateway $apiShip): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:cdek_point,yandex_point,ozon_point'],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $providerKey = DeliverySelection::providerKey($data['method']);

        if (! $providerKey || ! $apiShip->isProviderActive($providerKey)) {
            return response()->json(['message' => 'Эта служба пока не подключена. Укажите пожелание по ПВЗ.'], 503);
        }

        try {
            $offset = (int) ($data['offset'] ?? 0);
            $cacheKey = 'delivery-points:'.hash('sha256', implode('|', [
                $providerKey, mb_strtolower($data['city']), mb_strtolower($data['region'] ?? ''), $offset,
            ]));
            $result = Cache::remember($cacheKey, now()->addMinutes(10), fn () => $apiShip->points(
                $data['city'], $providerKey, $offset, self::PER_PAGE, $data['region'] ?? null,
            ));

            return response()->json([
                'points' => $result['points'],
                'has_more' => $offset + self::PER_PAGE < $result['total'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Не удалось получить ПВЗ.', [
                'provider' => $providerKey,
                'direct' => $apiShip->isDirect($providerKey),
                'error_type' => $exception::class,
            ]);

            return response()->json(['message' => 'Список ПВЗ временно недоступен. Укажите удобный район или адрес.'], 503);
        }
    }
}
