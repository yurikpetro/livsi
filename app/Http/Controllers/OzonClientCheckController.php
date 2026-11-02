<?php

namespace App\Http\Controllers;

use App\Services\ApiShipClient;
use App\Services\CartService;
use App\Services\OzonDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OzonClientCheckController extends Controller
{
    public function __invoke(Request $request, CartService $carts, ApiShipClient $apiShip, OzonDeliveryService $ozon): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        if (! $apiShip->isProviderActive('ozondel')) {
            return response()->json(['message' => 'Проверка Ozon пока не подключена.'], 503)->header('Cache-Control', 'no-store');
        }
        $cart = $carts->currentOrNull();
        if (! $cart || $cart->isEmpty()) {
            return response()->json(['message' => 'Добавьте товары в корзину перед выбором доставки.'], 422)->header('Cache-Control', 'no-store');
        }
        $phone = $ozon->normalizePhone($data['phone']);
        try {
            return response()->json(['can_be_delivered' => $ozon->canDeliver($phone, $request->session()->getId())])
                ->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Не удалось проверить доставку Ozon. Повторите проверку или выберите другую службу.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
