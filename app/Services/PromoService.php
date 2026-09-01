<?php

namespace App\Services;

use App\Models\PromoRule;
use Illuminate\Support\Collection;

/**
 * Пороги бесплатной доставки и подарка. Значения живут в промо-правилах
 * и меняются из админки — не в .env, иначе менять их пришлось бы
 * разработчику (docs/06-scope-v2.md §7.3).
 */
class PromoService
{
    public function freeShippingRule(string $channel = 'retail'): ?PromoRule
    {
        return $this->rule('free_shipping', $channel);
    }

    public function giftRule(string $channel = 'retail'): ?PromoRule
    {
        return $this->rule('gift', $channel);
    }

    /** Сколько копеек не хватает до бесплатной доставки; 0 — уже бесплатно. */
    public function remainingToFreeShipping(int $subtotal, string $channel = 'retail'): ?int
    {
        $rule = $this->freeShippingRule($channel);

        if (! $rule) {
            return null;
        }

        return max(0, $rule->threshold - $subtotal);
    }

    public function remainingToGift(int $subtotal, string $channel = 'retail'): ?int
    {
        $rule = $this->giftRule($channel);

        if (! $rule) {
            return null;
        }

        return max(0, $rule->threshold - $subtotal);
    }

    public function isGiftUnlocked(int $subtotal, string $channel = 'retail'): bool
    {
        $rule = $this->giftRule($channel);

        return $rule !== null && $subtotal >= $rule->threshold;
    }

    /** Варианты, доступные как подарок: покупатель выбирает один из них. */
    public function eligibleGifts(string $channel = 'retail'): Collection
    {
        $rule = $this->giftRule($channel);

        if (! $rule) {
            return collect();
        }

        return $rule->gifts()->with('product')->get();
    }

    private function rule(string $type, string $channel): ?PromoRule
    {
        return PromoRule::live()
            ->where('type', $type)
            ->whereIn('channel', [$channel, 'all'])
            ->orderBy('sort')
            ->first();
    }
}