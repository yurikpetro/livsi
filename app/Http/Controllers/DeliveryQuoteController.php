<?php

namespace App\Http\Controllers;

use App\Services\CartService;
use App\Services\DeliveryQuoteService;
use App\Services\DeliverySelection;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeliveryQuoteController extends Controller
{
    public function __invoke(Request $request, CartService $carts, DeliveryQuoteService $quotes): JsonResponse
    {
        $data = $request->validate([
            'delivery_city' => ['required', 'string', 'min:2', 'max:120'],
            'delivery_region' => ['nullable', 'string', 'max:120'],
            'delivery_point_method' => ['required', Rule::in(DeliverySelection::pickupMethods())],
            'delivery_point_id' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/'],
        ]);
        $data['delivery_region'] = $data['delivery_region'] ?? null;
        $cart = $carts->currentOrNull();
        if (! $cart || $cart->isEmpty()) {
            throw ValidationException::withMessages(['delivery_quote' => 'Корзина пуста.']);
        }
        try {
            $quote = $quotes->calculate($cart, $data);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Не удалось рассчитать доставку.', ['error_type' => $exception::class, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            throw ValidationException::withMessages(['delivery_quote' => 'Не удалось рассчитать доставку. Повторите расчёт или выберите другой пункт выдачи.']);
        }

        return response()->json([
            'token' => $quotes->token($quote, $request->session()->getId()),
            'test' => $quote['test'],
            'price' => $quote['price'], 'price_label' => $quote['price'] === 0 ? 'Бесплатно' : Money::rub($quote['price']),
            'tariff_name' => $quote['tariff_name'], 'days_min' => $quote['days_min'], 'days_max' => $quote['days_max'],
        ])->header('Cache-Control', 'no-store');
    }
}
