<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Mailing;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Промокоды: проверка при оформлении, расчёт скидки, выпуск личных кодов.
 *
 * Скидка ложится в заказ так же, как подарок, — распределяется по товарам,
 * поэтому сходится и в первом чеке, и во втором. Бесплатная доставка —
 * цена доставки для покупателя 0 (службе платим мы).
 */
class PromoCodeService
{
    /** Хотя бы рубль товара остаётся к оплате: строки чека не бывают нулевыми, а платёж — пустым. */
    private const KEEP = 100;

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', (string) $code));
    }

    public function find(?string $code): ?PromoCode
    {
        $code = self::normalize($code);

        return $code === '' ? null : PromoCode::query()->where('code', $code)->first();
    }

    /**
     * Подходит ли код к этой покупке. Возвращает, что он даёт, или бросает
     * отказ с понятной причиной (поле promo_code).
     *
     * @param  int  $goods  сумма товаров к оплате (после подарка), копейки
     * @return array{promo: PromoCode, discount: int, free_delivery: bool, title: string}
     */
    public function check(?string $code, int $goods, string $email, ?string $phone, ?User $user): array
    {
        $promo = $this->find($code);
        if (! $promo) {
            $this->fail('Такого промокода нет. Проверьте, нет ли опечатки.');
        }
        if (! $promo->active) {
            $this->fail('Этот промокод больше не действует.');
        }
        if ($promo->expires_at && $promo->expires_at->isPast()) {
            $this->fail('Срок действия промокода закончился '.$promo->expires_at->timezone('Europe/Moscow')->format('d.m.Y').'.');
        }
        if ($promo->customer_id && ! $this->belongs($promo->customer, $email, $phone, $user)) {
            $this->fail('Это личный промокод другого покупателя. Если он ваш — укажите почту или телефон, на которые его получили.');
        }
        if ($promo->min_total && $goods < $promo->min_total) {
            $this->fail('Промокод действует на заказ от '.Money::rub((int) $promo->min_total).' — добавьте товаров ещё на '.Money::rub((int) $promo->min_total - $goods).'.');
        }
        if ($promo->max_uses !== null && $promo->liveOrders()->count() >= $promo->max_uses) {
            $this->fail('Этот промокод уже использован.');
        }
        if ($promo->once_per_customer && ! $promo->customer_id && $this->usedBy($promo, $email, $phone)) {
            $this->fail('Вы уже использовали этот промокод.');
        }

        $room = max(0, $goods - self::KEEP);
        $discount = match ($promo->kind) {
            PromoCode::PERCENT => min($room, intdiv($goods * min(99, (int) $promo->value), 100)),
            PromoCode::FIXED => min($room, (int) $promo->value),
            default => 0,
        };
        if (in_array($promo->kind, [PromoCode::PERCENT, PromoCode::FIXED], true) && $discount < 1) {
            $this->fail('К этой корзине скидку по промокоду применить нельзя.');
        }

        return ['promo' => $promo, 'discount' => $discount, 'free_delivery' => $promo->kind === PromoCode::FREE_DELIVERY,
            'title' => $promo->benefit()];
    }

    /**
     * Выпустить личный код клиенту (или общий, если клиента нет).
     *
     * @param  array{kind: string, value?: int, min_total?: ?int, days?: ?int, note?: ?string}  $spec
     */
    public function issue(array $spec, ?Customer $customer, string $by, ?Mailing $mailing = null): PromoCode
    {
        $kind = $spec['kind'];
        if (! array_key_exists($kind, PromoCode::kinds())) {
            $this->fail('Неизвестный вид промокода.');
        }
        $value = (int) ($spec['value'] ?? 0);
        if ($kind === PromoCode::PERCENT && ($value < 1 || $value > 99)) {
            $this->fail('Скидка в процентах — от 1 до 99.');
        }
        if ($kind === PromoCode::FIXED && $value < 100) {
            $this->fail('Скидка в рублях — от 1 ₽.');
        }
        $days = isset($spec['days']) && $spec['days'] ? (int) $spec['days'] : null;

        for ($try = 0; ; $try++) {
            $code = 'LIVSI-'.Str::upper(Str::random(6));
            if (! PromoCode::query()->where('code', $code)->exists() || $try > 5) {
                break;
            }
        }

        return PromoCode::query()->create([
            'code' => $code,
            'kind' => $kind,
            'value' => $kind === PromoCode::FREE_DELIVERY ? 0 : $value,
            'min_total' => isset($spec['min_total']) && $spec['min_total'] ? (int) $spec['min_total'] : null,
            'expires_at' => $days ? now()->addDays($days)->endOfDay() : null,
            'max_uses' => $customer ? 1 : null,
            'once_per_customer' => true,
            'customer_id' => $customer?->id,
            'mailing_id' => $mailing?->id,
            'note' => isset($spec['note']) ? mb_substr((string) $spec['note'], 0, 255) : null,
            'issued_by' => mb_substr($by, 0, 120),
            'active' => true,
        ]);
    }

    /** Личные коды, которые клиент видит в кабинете. */
    public function forCustomer(?Customer $customer)
    {
        return $customer
            ? $customer->promoCodes()->with('liveOrders')->latest('id')->get()
            : collect();
    }

    private function belongs(?Customer $customer, string $email, ?string $phone, ?User $user): bool
    {
        if (! $customer) {
            return false;
        }

        return ($customer->email && $customer->email === Customer::normalizeEmail($email))
            || ($customer->phone && $customer->phone === Customer::normalizePhone($phone))
            || ($user && $customer->user_id === $user->id);
    }

    private function usedBy(PromoCode $promo, string $email, ?string $phone): bool
    {
        $email = Customer::normalizeEmail($email);

        return $promo->liveOrders()->where(fn ($q) => $q->whereRaw('lower(customer_email) = ?', [$email])
            ->when(Customer::normalizePhone($phone), fn ($q, $digits) => $q->orWhereHas('customer', fn ($c) => $c->where('phone', $digits))))
            ->exists();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
