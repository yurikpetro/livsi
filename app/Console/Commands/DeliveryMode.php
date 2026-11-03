<?php

namespace App\Console\Commands;

use App\Delivery\DeliveryGateway;
use App\Delivery\DeliverySettings;
use Illuminate\Console\Command;

/**
 * Режим службы: через ApiShip или напрямую. То же, что переключатель на
 * странице «Интеграции доставки», — для консоли и установщика.
 *
 *   php artisan delivery:mode                 — показать режимы
 *   php artisan delivery:mode cdek direct     — СДЭК напрямую
 *   php artisan delivery:mode cdek apiship    — СДЭК через ApiShip
 */
class DeliveryMode extends Command
{
    protected $signature = 'delivery:mode {provider? : cdek, yataxi или ozondel} {mode? : direct или apiship}';

    protected $description = 'Режим службы доставки: напрямую или через ApiShip';

    public function handle(): int
    {
        $provider = $this->argument('provider');
        if ($provider !== null) {
            if (! in_array($provider, DeliveryGateway::PROVIDERS, true) || ! in_array($this->argument('mode'), ['direct', 'apiship'], true)) {
                $this->error('Так: php artisan delivery:mode cdek direct (службы: '.implode(', ', DeliveryGateway::PROVIDERS).'; режим direct или apiship)');

                return self::INVALID;
            }
            DeliverySettings::setDriver($provider, $this->argument('mode'));
        }
        $names = ['cdek' => 'СДЭК', 'yataxi' => 'Яндекс', 'ozondel' => 'Ozon'];
        foreach (DeliveryGateway::PROVIDERS as $p) {
            $this->line(sprintf('  %s: %s', $names[$p] ?? $p, DeliverySettings::driver($p) === 'direct' ? 'напрямую' : 'через ApiShip'));
        }

        return self::SUCCESS;
    }
}
