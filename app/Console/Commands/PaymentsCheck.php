<?php

namespace App\Console\Commands;

use App\Payments\CloudKassir;
use App\Payments\PaymentSettings;
use App\Payments\TochkaClient;
use App\Payments\TrustedCa;
use App\Payments\Vat;
use App\Payments\YooKassaGateway;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Проверка подключения к ЮKassa.
 *
 * Нужна ровно один раз — когда ключи вставлены в окружение и надо понять,
 * принимает ли их провайдер, до того как этим займётся первый покупатель.
 * Запрос делается на список платежей: он ничего не создаёт и не стоит денег.
 */
class PaymentsCheck extends Command
{
    protected $signature = 'payments:check {--tochka : проверить Точку и CloudKassir, даже если оплата пока через ЮKassa}';

    protected $description = 'Проверить ключи платёжного провайдера и готовность к приёму оплаты';

    public function handle(YooKassaGateway $gateway, TochkaClient $tochka, CloudKassir $kassa): int
    {
        $this->line('');
        $this->line('Оплату принимает: '.PaymentSettings::providerLabel());

        if (PaymentSettings::provider() === PaymentSettings::TOCHKA || $this->option('tochka')) {
            return $this->tochka($tochka, $kassa);
        }

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

    /** Точка: ключ, эквайринг, уведомления; CloudKassir — для второго чека. */
    private function tochka(TochkaClient $tochka, CloudKassir $kassa): int
    {
        $this->line('Сертификат Минцифры для связи с Точкой: '.(TrustedCa::installed() ? 'установлен' : 'НЕ установлен'));
        if (PaymentSettings::secret('tochka_jwt') === null) {
            $this->error('Не задан JWT-ключ Точки — «Интеграции оплаты» в админке.');

            return self::FAILURE;
        }
        try {
            $this->line('Код компании: '.$tochka->ensureCustomerCode());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! Vat::isConfigured()) {
            $this->error('Не определена ставка НДС — чек сформировать нельзя. Задайте её в настройках сайта.');

            return self::FAILURE;
        }
        try {
            $retailers = $tochka->retailers();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $ready = false;
        foreach ($retailers as $row) {
            $ready = $ready || (($row['status'] ?? '') === 'REG' && ($row['isActive'] ?? false));
            $this->line(sprintf('Торговая точка %s: %s, %s, ID %s', $row['name'] ?? '', $row['status'] ?? '?',
                ($row['isActive'] ?? false) ? 'работает' : 'не активна', $row['merchantId'] ?? '—'));
        }
        if (! $ready) {
            $this->error('Эквайринг Точки ещё не готов к приёму оплаты.');

            return self::FAILURE;
        }
        $this->info('Точка: ключ принят, эквайринг работает.');
        $this->line(PaymentSettings::option('tochka_webhook_at')
            ? 'Уведомления об оплате подключены: '.route('webhooks.tochka')
            : 'Уведомления об оплате не подключены — кнопка в «Интеграциях оплаты». Без них статус обновится при возврате покупателя на сайт.');

        if (! $kassa->isConfigured()) {
            $this->warn('CloudKassir не настроен — второй чек по оплатам Точки не уйдёт.');

            return self::SUCCESS;
        }
        try {
            $kassa->ping();
            $this->info('CloudKassir: ключи приняты.');
        } catch (Throwable $e) {
            $this->warn($e->getMessage());
        }
        $this->line('');

        return self::SUCCESS;
    }
}
