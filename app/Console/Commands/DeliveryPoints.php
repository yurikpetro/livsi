<?php

namespace App\Console\Commands;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use App\Delivery\PointSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Загрузить пункты выдачи служб в базу сайта.
 *
 * Без аргумента — все службы, у которых заданы ключи, даже если они ещё
 * работают через ApiShip: так пункты можно загрузить заранее, а потом
 * переключить службу без пустой карты.
 *
 *   php artisan delivery:points          — все подключённые
 *   php artisan delivery:points cdek     — одна служба (cdek, yataxi, ozondel)
 */
class DeliveryPoints extends Command
{
    protected $signature = 'delivery:points {provider? : cdek, yataxi или ozondel}
        {--requested : только службы, для которых нажали «Обновить пункты» в админке}';

    protected $description = 'Загрузить пункты выдачи служб доставки';

    public function handle(DeliveryGateway $gateway, PointSync $sync): int
    {
        // Списки служб большие: у Ozon около 90 тысяч пунктов.
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        $providers = $this->argument('provider') ? [$this->argument('provider')] : DeliveryGateway::PROVIDERS;
        if ($this->option('requested')) {
            // Кнопка в админке ставит заявку, расписание раз в минуту её
            // забирает: загрузка идёт минуты, держать для неё запрос
            // браузера нельзя.
            $providers = DeliverySettings::takeSyncRequests();
            if ($providers === []) {
                return self::SUCCESS;
            }
        }
        $failed = false;

        foreach ($providers as $provider) {
            if (! in_array($provider, DeliveryGateway::PROVIDERS, true)) {
                $this->error('Нет такой службы: '.$provider.'. Можно: '.implode(', ', DeliveryGateway::PROVIDERS));

                return self::INVALID;
            }
            $carrier = $gateway->carrier($provider);
            if (! $carrier->isConfigured()) {
                $this->line($carrier->title().': ключи не заданы — пропускаю.');

                continue;
            }
            $started = microtime(true);
            DeliverySettings::setRunning($provider, true);
            try {
                $result = $sync->run($carrier);
                DeliverySettings::recordSync($provider, $result + ['seconds' => (int) (microtime(true) - $started)]);
                $this->info(sprintf('%s: загружено %d, закрыто %d, пропущено %d — за %d с.',
                    $carrier->title(), $result['loaded'], $result['disabled'], $result['skipped'], (int) (microtime(true) - $started)));
            } catch (Throwable $exception) {
                $failed = true;
                DeliverySettings::recordSync($provider, ['error' => mb_substr($exception->getMessage(), 0, 300)]);
                Log::error('Пункты выдачи не загружены.', ['provider' => $provider,
                    'error' => mb_substr($exception->getMessage(), 0, 500)]);
                $this->error($carrier->title().': '.mb_substr($exception->getMessage(), 0, 500));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
