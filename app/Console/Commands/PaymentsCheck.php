<?php

namespace App\Console\Commands;

use App\Payments\Vat;
use App\Payments\YooKassaGateway;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Проверка подключения к ЮKassa.
 *
 * Нужна ровно один раз — когда ключи вставлены в окружение и надо понять,
 * принимает ли их провайдер, до того как этим займётся первый покупатель.
 * Запрос делается на список платежей: он ничего не создаёт и не стоит денег.
 */
class PaymentsCheck extends Command
{
    protected $signature = 'payments:check';

    protected $description = 'Проверить ключи ЮKassa и готовность к приёму оплаты';

    public function handle(YooKassaGateway $gateway): int
    {
        $this->line('');

        if (! $gateway->isConfigured()) {
            $this->error('Ключи не заданы. Заполните YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY.');

            return self::FAILURE;
        }

        $this->info($gateway->isTestMode()
            ? 'Режим: тестовый магазин (ключ начинается с test_).'
            : 'Режим: БОЕВОЙ. Платежи будут настоящими.');

        if (! Vat::isConfigured()) {
            $this->error('Не определена ставка НДС — чек сформировать нельзя. Задайте её в настройках сайта.');

            return self::FAILURE;
        }

        $this->line('Ставка НДС: ' . (Vat::sellerIsNotPayer() ? 'продавец не платит НДС' : Vat::defaultRate() . ' %'));

        try {
            $response = Http::baseUrl(config('livsi.yookassa.api_url'))
                ->withBasicAuth(
                    (string) config('livsi.yookassa.shop_id'),
                    (string) config('livsi.yookassa.secret_key'),
                )
                ->acceptJson()
                ->timeout(20)
                ->get('/payments', ['limit' => 1]);
        } catch (ConnectionException $e) {
            // До провайдера не дошли вовсе. Чаще всего это не сеть,
            // а отсутствие списка корневых сертификатов у PHP: на Windows
            // он не входит в поставку, и любой HTTPS-запрос падает.
            $this->error('Соединение не установлено: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'certificate')) {
                $this->newLine();
                $this->line('У PHP нет списка корневых сертификатов — проверить нечем, поэтому он отвергает любой HTTPS.');
                $this->line('Возьмите набор сертификатов (curl.se/ca/cacert.pem или ca-bundle.crt из состава Git)');
                $this->line('и пропишите путь к файлу в php.ini двумя строками:');
                $this->newLine();
                $this->line('    curl.cainfo = "путь\к\cacert.pem"');
                $this->line('    openssl.cafile = "путь\к\cacert.pem"');
                $this->newLine();
                $this->line('Проверка ключей провайдера не пройдена — она делается после соединения.');
            }

            return self::FAILURE;
        }

        if ($response->status() === 401) {
            $this->error('Провайдер не принял ключи: 401. Проверьте идентификатор магазина и секретный ключ.');

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('Провайдер ответил ошибкой ' . $response->status() . ': ' . $response->body());

            return self::FAILURE;
        }

        $this->info('Ключи приняты, API отвечает.');
        $this->line('Адрес для уведомлений: ' . route('webhooks.yookassa'));
        $this->line('Его нужно указать в личном кабинете ЮKassa — без этого статус оплаты будет обновляться только при возврате покупателя на сайт.');
        $this->line('');

        return self::SUCCESS;
    }
}
