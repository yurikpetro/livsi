<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Справочник клиентов: каждый заказ и каждый зарегистрированный пользователь
 * попадают сюда по почте. Имя, телефон и город — из последнего заказа;
 * согласие на рассылку — из последнего заказа с галочкой.
 */
class CustomerDirectory
{
    public function remember(string $email, ?string $name, ?string $phone, ?string $city, ?int $userId, mixed $consentAt): ?Customer
    {
        $email = Customer::normalizeEmail($email);
        if ($email === null) {
            return null;
        }
        try {
            $customer = Customer::query()->firstOrCreate(['email' => $email]);
        } catch (UniqueConstraintViolationException) {
            $customer = Customer::query()->where('email', $email)->firstOrFail();
        }

        $consent = $consentAt ? Carbon::parse($consentAt) : null;
        $customer->fill(array_filter([
            'name' => filled($name) ? mb_substr(trim((string) $name), 0, 255) : null,
            'phone' => Customer::normalizePhone($phone),
            'city' => filled($city) ? mb_substr(trim((string) $city), 0, 120) : null,
            'user_id' => $customer->user_id ?: $userId,
        ], fn ($value) => $value !== null));
        if ($consent && (! $customer->marketing_consent_at || $consent->gt($customer->marketing_consent_at))) {
            $customer->marketing_consent_at = $consent;
        }
        $customer->save();

        return $customer;
    }

    public function attachOrder(Order $order): void
    {
        $customer = $this->remember((string) $order->customer_email, $order->customer_name, $order->customer_phone,
            $order->delivery_city, $order->user_id, $order->marketing_consent_at);
        if ($customer && $order->customer_id !== $customer->id) {
            $order->forceFill(['customer_id' => $customer->id])->saveQuietly();
        }
    }

    public function attachUser(User $user): void
    {
        if (filled($user->email)) {
            $this->remember((string) $user->email, $user->name, $user->phone, null, $user->id, null);
        }
    }

    /** Клиент того, кто вошёл: по пользователю или по его почте. */
    public function forUser(?User $user): ?Customer
    {
        if (! $user) {
            return null;
        }

        return Customer::query()->where('user_id', $user->id)->first()
            ?? (filled($user->email) ? Customer::query()->where('email', Customer::normalizeEmail($user->email))->first() : null);
    }
}
