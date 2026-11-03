<?php

namespace App\Console\Commands;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Delivery\PointNames;
use App\Models\DeliveryPoint;
use Illuminate\Console\Command;
use Throwable;

/**
 * Пункты выдачи города, куда служба считает несуразно дорого или долго.
 *
 * Спрашивает у службы цену до каждого пункта города для обычной посылки
 * (300 г, 17×7×7 см) и сравнивает с ценой большинства пунктов. Так нашлись
 * два пункта Яндекса в Ставрополе: 918 ₽ и 9 дней при обычных 158 ₽ и 3 днях.
 * С --hide найденные пункты сразу прячутся с карты оформления.
 */
class DeliveryOutliers extends Command
{
    protected $signature = 'delivery:outliers {provider : cdek, yataxi или ozondel}
        {--city=Ставрополь : город}
        {--hide : спрятать найденные пункты с карты}
        {--limit=150 : сколько пунктов проверить}';

    protected $description = 'Найти пункты выдачи с несуразной ценой или сроком доставки';

    public function handle(DeliveryGateway $gateway): int
    {
        $provider = (string) $this->argument('provider');
        if (! in_array($provider, DeliveryGateway::PROVIDERS, true)) {
            $this->error('Можно: '.implode(', ', DeliveryGateway::PROVIDERS));

            return self::INVALID;
        }
        if (! $gateway->isDirect($provider)) {
            $this->warn('Служба работает через ApiShip — проверка только для прямого подключения.');

            return self::FAILURE;
        }
        $city = (string) $this->option('city');
        $points = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)
            ->where('city_key', PointNames::cityKey($city))->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        $this->info(sprintf('%s, %s: пунктов %d', $provider, $city, $points->count()));
        if ($points->isEmpty()) {
            return self::SUCCESS;
        }

        $carrier = $gateway->carrier($provider);
        $parcel = ['weight' => 300, 'length' => 17, 'width' => 7, 'height' => 7];
        $quotes = [];
        foreach ($points as $point) {
            try {
                $tariff = $carrier->tariffs($parcel, $point->toPublic(), ['assessed' => 100000, 'items' => []])[0] ?? null;
            } catch (Throwable $exception) {
                $tariff = null;
            }
            if ($tariff) {
                $quotes[] = ['point' => $point, 'cost' => (int) $tariff['cost'], 'days' => $tariff['days_max'] ?? $tariff['days_min'] ?? null];
            }
        }
        if (! $quotes) {
            $this->warn('Служба не посчитала ни один пункт.');

            return self::FAILURE;
        }

        $costs = array_column($quotes, 'cost');
        sort($costs);
        $median = $costs[intdiv(count($costs), 2)];
        $this->line(sprintf('обычная цена: %s ₽ (по %d пунктам)', number_format($median / 100, 2, ',', ' '), count($costs)));
        $outliers = array_values(array_filter($quotes, fn (array $quote): bool => self::isOutlier($quote['cost'], $median)));
        if (! $outliers) {
            $this->info('Несуразных пунктов нет.');

            return self::SUCCESS;
        }
        $hidden = DeliverySettings::hiddenPoints($provider);
        foreach ($outliers as $quote) {
            $this->line(sprintf('  %s  %s ₽%s  %s%s', $quote['point']->external_id, number_format($quote['cost'] / 100, 2, ',', ' '),
                $quote['days'] ? ', '.$quote['days'].' дн.' : '', $quote['point']->address,
                in_array($quote['point']->external_id, $hidden, true) ? '  (уже скрыт)' : ''));
        }
        if ($this->option('hide')) {
            DeliverySettings::setHiddenPoints($provider, [...$hidden, ...array_map(fn (array $quote): string => $quote['point']->external_id, $outliers)]);
            $this->info('Спрятаны с карты оформления. Вернуть — «Интеграции доставки» → «Скрытые пункты выдачи».');
        } else {
            $this->line('Спрятать их с карты: добавьте --hide.');
        }

        return self::SUCCESS;
    }

    /** Дороже обычного в полтора раза и больше чем на 150 ₽. */
    public static function isOutlier(int $cost, int $usual): bool
    {
        return $usual > 0 && $cost > (int) round($usual * 1.5) && $cost - $usual > 15000;
    }
}
