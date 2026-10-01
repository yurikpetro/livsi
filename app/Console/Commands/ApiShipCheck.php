<?php

namespace App\Console\Commands;

use App\Services\ApiShipClient;
use Illuminate\Console\Command;
use Throwable;

class ApiShipCheck extends Command
{
    protected $signature = 'apiship:check {--city= : Населённый пункт} {--region= : Проверить ПВЗ всего региона}';

    protected $description = 'Проверить доступ к ПВЗ ApiShip без создания отправлений';

    public function handle(ApiShipClient $apiShip): int
    {
        if (! $apiShip->isConfigured()) {
            $this->error('Токен не задан. Заполните APISHIP_TOKEN в .env.');

            return self::FAILURE;
        }

        $city = $this->option('city') ?: config('livsi.delivery.origin_city');
        $region = $this->option('region') ?: null;
        $this->line('Проверка ПВЗ для '.($region ?: 'города '.$city));
        $failed = false;

        foreach (config('livsi.apiship.active_providers', []) as $providerKey) {
            try {
                $result = $apiShip->points($city, $providerKey, 0, 20, $region);
                $this->info($providerKey.': '.$result['total'].' ПВЗ, подходящих в первой двадцатке: '.count($result['points']));

                if ($result['points'] !== []) {
                    $first = $result['points'][0];
                    $verified = $apiShip->point($first['id'], $city, $providerKey, $region);
                    $this->line('Проверка выбранного ПВЗ: '.($verified ? 'успешна' : 'не пройдена'));
                    $failed = $failed || ! $verified;
                }
            } catch (Throwable $exception) {
                $this->error($providerKey.': API не ответил успешно ('.$exception::class.').');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
