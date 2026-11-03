<?php

namespace App\Console\Commands;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Delivery\PointNames;
use App\Models\DeliveryPoint;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Проверка прямой службы доставки перед переключением.
 *
 *   php artisan delivery:check cdek
 *   php artisan delivery:check yataxi
 *   php artisan delivery:check ozondel
 *
 * Показывает: заданы ли ключи и принимает ли их служба, куда в Ставрополе
 * можно сдавать посылки (номер — в настройки сайта), сколько пунктов
 * выдачи загружено, и пробный расчёт до Москвы. Заказов не создаёт.
 */
class DeliveryCheck extends Command
{
    protected $signature = 'delivery:check {provider : cdek, yataxi или ozondel}
        {--city=Ставрополь : город пунктов сдачи}
        {--origin= : номер пункта сдачи из списка — сохранить в настройки сайта}';

    protected $description = 'Проверить прямое подключение службы доставки';

    public function handle(DeliveryGateway $gateway): int
    {
        $provider = (string) $this->argument('provider');
        if (! in_array($provider, DeliveryGateway::PROVIDERS, true)) {
            $this->error('Можно: '.implode(', ', DeliveryGateway::PROVIDERS));

            return self::INVALID;
        }
        $carrier = $gateway->carrier($provider);
        $this->info($carrier->title());
        $this->line('  работает сейчас: '.($gateway->isDirect($provider) ? 'напрямую' : 'через ApiShip'));
        if (! $carrier->isConfigured()) {
            $this->warn('  ключи не заданы — дальше проверять нечего');

            return self::FAILURE;
        }
        $this->line('  ключи заданы'.($this->testMode($provider) ? ' (тестовая среда службы)' : ''));

        if (method_exists($carrier, 'dropoffPoints')) {
            try {
                $points = $carrier->dropoffPoints((string) $this->option('city'));
                $this->line('  пункты сдачи посылок, '.$this->option('city').': '.count($points));
                foreach (array_slice($points, 0, 15) as $point) {
                    $this->line('    '.$point['external_id'].'  '.$point['address']);
                }
                $this->line('  выбрать пункт удобнее в админке: «Интеграции доставки»');
            } catch (Throwable $exception) {
                $this->error('  служба не ответила: '.mb_substr($exception->getMessage(), 0, 300));

                return self::FAILURE;
            }
        }
        if (! method_exists($carrier, 'dropoffPoints') && method_exists($carrier, 'ping')) {
            try {
                $carrier->ping();
                $this->line('  служба приняла ключи');
            } catch (Throwable $exception) {
                $this->error('  служба не ответила: '.mb_substr($exception->getMessage(), 0, 300));

                return self::FAILURE;
            }
        }
        if (filled($this->option('origin')) && $provider === 'ozondel') {
            if (! ctype_digit((string) $this->option('origin'))) {
                $this->error('  ID метода отгрузки Ozon — это число из кабинета Ozon Доставки');

                return self::FAILURE;
            }
            DeliverySettings::setOrigin('ozondel', (string) $this->option('origin'));
            $this->info('  сохранено: метод отгрузки Ozon '.$this->option('origin'));
        }
        if (filled($this->option('origin')) && isset($points)) {
            $chosen = collect($points)->firstWhere('external_id', (string) $this->option('origin'));
            if (! $chosen) {
                $this->error('  пункта '.$this->option('origin').' нет в списке выше — ничего не сохранено');

                return self::FAILURE;
            }
            DeliverySettings::setOrigin($provider, $chosen['external_id'], $chosen['address']);
            $this->info('  сохранено: сдаём посылки в '.$chosen['external_id'].', '.$chosen['address']);
        }
        $origin = DeliverySettings::origin($provider);
        $this->line('  '.($provider === 'ozondel' ? 'метод отгрузки' : 'пункт сдачи').' в настройках: '.($origin ?: 'не выбран'));

        $count = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)->count();
        $this->line('  пунктов выдачи загружено: '.$count.($count ? '' : ' — запустите php artisan delivery:points '.$provider));
        if (! $count || ! $origin) {
            return self::SUCCESS;
        }

        $target = DeliveryPoint::query()->where('provider', $provider)->where('is_active', true)
            ->where('city_key', PointNames::cityKey('Москва'))->where('type', 'pvz')->first();
        if (! $target) {
            return self::SUCCESS;
        }
        $variant = ProductVariant::query()->where('dimensions_verified', true)->whereNotNull('weight_g')->first();
        try {
            $tariffs = $carrier->tariffs(['weight' => 1000, 'length' => 20, 'width' => 15, 'height' => 10], $target->toPublic(), [
                'assessed' => 200000,
                'items' => $variant ? [['sku' => $variant->sku, 'quantity' => 1, 'price' => (int) $variant->price]] : [],
            ]);
            $best = $tariffs[0] ?? null;
            $this->line('  пробный расчёт до Москвы (1 кг, 20×15×10): '.($best
                ? number_format($best['cost'] / 100, 2, ',', ' ').' ₽, '.$best['tariff_name']
                    .($best['days_min'] ? ', '.$best['days_min'].'–'.($best['days_max'] ?? $best['days_min']).' дн.' : '')
                : 'тарифов нет'));
        } catch (Throwable $exception) {
            $this->warn('  пробный расчёт не прошёл: '.mb_substr($exception->getMessage(), 0, 300));
        }

        return self::SUCCESS;
    }

    private function testMode(string $provider): bool
    {
        return match ($provider) {
            'cdek' => (bool) config('livsi.cdek.test'),
            'yataxi' => (bool) config('livsi.yandex_delivery.test'),
            default => false,
        };
    }
}
