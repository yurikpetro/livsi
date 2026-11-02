<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class OzonDeliveryService
{
    public function __construct(private readonly ApiShipClient $apiShip) {}

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '7'.$digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }
        if (! preg_match('/^[+\d\s().-]+$/u', $phone) || ! preg_match('/^7[3489]\d{9}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => 'Для проверки Ozon укажите полный российский номер телефона.']);
        }

        return '+'.$digits;
    }

    public function canDeliver(string $phone, string $sessionId): bool
    {
        $phone = $this->normalizePhone($phone);
        $key = 'ozon-client:'.hash_hmac('sha256', implode('|', [
            $sessionId, $phone, (string) config('livsi.apiship.ozon_connection_id'),
        ]), (string) config('app.key'));

        return Cache::remember($key, now()->addMinutes(5), fn (): bool => $this->apiShip->canDeliverToOzonClient($phone));
    }
}
