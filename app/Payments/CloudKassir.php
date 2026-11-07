<?php

namespace App\Payments;

use App\Models\Order;
use App\Support\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Касса CloudKassir — чеки, которые сайт пробивает сам, когда оплату
 * принимает Точка: второй чек «полный расчёт» с кодами маркировки и
 * чеки возврата.
 *
 * Первый чек (предоплата) сюда не ходит: его пробивает связка Точка —
 * CloudKassir при оплате по ссылке.
 *
 * Доступ — Public ID и API Secret из кабинета CloudKassir (Basic-аутентификация).
 * Повтор безопасен: запрос идёт с X-Request-ID, и CloudKassir час помнит
 * ответ на него — обрыв связи не пробьёт второй чек.
 */
class CloudKassir
{
    public const INCOME = 'Income';

    public const INCOME_RETURN = 'IncomeReturn';

    /** Статус штуки для «Честного знака»: продана / возвращена. */
    public const MARK_SOLD = 1;

    public const MARK_RETURNED = 3;

    /** Код ставки НДС (как в Vat) → ставка в чеке CloudKassir; null — без НДС. */
    private const VAT = [1 => null, 2 => 0, 3 => 10, 4 => 20, 7 => 5, 8 => 7, 11 => 22];

    public function isConfigured(): bool
    {
        return PaymentSettings::cloudKassirPublicId() !== null
            && PaymentSettings::secret('cloudkassir_secret') !== null
            && PaymentSettings::inn() !== null;
    }

    /**
     * Тело чека из строк ReceiptBuilder (те же суммы по штукам, что в первом чеке).
     *
     * @param  list<array<string, mixed>>  $items  строки ReceiptBuilder::closingItems() или build()['items']
     * @param  string  $settlement  AdvancePayment — зачёт предоплаты; Electronic — деньги на карту/счёт
     * @return array<string, mixed>
     */
    public function body(Order $order, string $type, array $items, string $settlement): array
    {
        $lines = [];
        $total = 0;
        foreach ($items as $item) {
            $line = $this->item($item, $type === self::INCOME_RETURN ? self::MARK_RETURNED : self::MARK_SOLD);
            $total += (int) round($line['Price'] * 100) * (int) $line['Quantity'];
            $lines[] = $line;
        }
        $tax = PaymentSettings::taxSystem();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return [
            'Inn' => PaymentSettings::inn(),
            'Type' => $type,
            'InvoiceId' => $order->number,
            'CustomerReceipt' => array_filter([
                'Items' => $lines,
                'TaxationSystem' => $tax !== null ? PaymentSettings::TAX_SYSTEMS[$tax]['cloudkassir'] : null,
                'Email' => $order->customer_email ?: null,
                'Phone' => Phone::format($order->customer_phone),
                'CalculationPlace' => is_string($host) && $host !== '' ? $host : null,
                'IsInternetPayment' => true,
                'Amounts' => [$settlement => round($total / 100, 2)],
            ], fn ($value) => $value !== null),
        ];
    }

    /** Сумма чека в копейках. @param array<string, mixed> $body */
    public static function total(array $body): int
    {
        $total = 0;
        foreach ((array) ($body['CustomerReceipt']['Items'] ?? []) as $item) {
            $total += (int) round(((float) $item['Price']) * 100) * (int) $item['Quantity'];
        }

        return $total;
    }

    /**
     * Отправить чек. Ответ — Model: Id чека в CloudKassir (итог — status()).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function send(array $body, string $requestId): array
    {
        $response = $this->client()->withHeaders(['X-Request-ID' => $requestId])
            ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->post('kkt/receipt', $body);
        $json = $this->json($response, 'чек');
        $model = (array) ($json['Model'] ?? []);
        if (blank($model['Id'] ?? null)) {
            throw new RuntimeException('CloudKassir не вернул номер чека.');
        }

        return $model + ['Warning' => $json['Warning'] ?? null];
    }

    /** Queued — в очереди, Processed — пробит, Error — не пробит, NotFound — нет такого. */
    public function status(string $id): string
    {
        $json = $this->json($this->client()->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->post('kkt/receipt/status/get', ['Id' => $id]), 'статус чека');

        return (string) ($json['Model'] ?? '');
    }

    /** Проверка ключей: ничего не пробивает. */
    public function ping(): void
    {
        $this->json($this->client()->post('test', []), 'проверка связи');
    }

    /**
     * @param  array<string, mixed>  $item  строка в формате ReceiptBuilder
     * @return array<string, mixed>
     */
    private function item(array $item, int $markStatus): array
    {
        $price = round((float) $item['amount']['value'], 2);
        $quantity = (int) $item['quantity'];
        $subject = (string) ($item['payment_subject'] ?? 'commodity');
        $line = [
            'Label' => mb_substr((string) $item['description'], 0, 128),
            'Price' => $price,
            'Quantity' => $quantity,
            'Amount' => round(((int) round($price * 100)) * $quantity / 100, 2),
            'Vat' => self::vat((int) $item['vat_code']),
            'Method' => ($item['payment_mode'] ?? '') === 'full_payment' ? 4 : 1,
            // 33 — товар с кодом маркировки (ФФД 1.2), 4 — услуга, 1 — товар.
            'Object' => match ($subject) {
                'marked' => 33,
                'service' => 4,
                default => 1,
            },
            'UnitCode' => 0,
        ];
        $code = $item['mark_code_info']['gs_1m'] ?? null;
        if ($subject === 'marked' && is_string($code) && $code !== '') {
            // Код целиком, как отсканирован: с крипто-хвостом и разделителем GS.
            $line['RawMarkCode'] = $code;
            $line['ExpectedMarkItemStatus'] = $markStatus;
        }

        return $line;
    }

    private static function vat(int $code): ?int
    {
        if (! array_key_exists($code, self::VAT)) {
            throw new RuntimeException('Для кода ставки НДС '.$code.' нет соответствия в чеке CloudKassir. Проверьте ставку в настройках сайта.');
        }

        return self::VAT[$code];
    }

    /** @return array<string, mixed> */
    private function json(Response $response, string $what): array
    {
        $json = $response->json();
        if ($response->status() === 401) {
            Log::warning('CloudKassir не принял ключи.', ['what' => $what]);

            throw new RuntimeException('CloudKassir, '.$what.': ключи не приняты (проверьте Public ID и API Secret).');
        }
        if ($response->successful() && is_array($json) && ($json['Success'] ?? false) === true) {
            return $json;
        }
        $text = is_array($json) ? trim((string) ($json['Message'] ?? '')) : '';
        $code = is_array($json) ? ($json['Model']['ErrorCode'] ?? null) : null;
        Log::warning('CloudKassir отказал: '.$what, ['status' => $response->status(), 'message' => mb_substr($text, 0, 300), 'code' => $code]);

        throw new RuntimeException('CloudKassir, '.$what.': '.($text !== '' ? $text : 'HTTP '.$response->status()).($code !== null && $code !== 0 ? ' (код '.$code.')' : ''));
    }

    private function client(): PendingRequest
    {
        $id = PaymentSettings::cloudKassirPublicId();
        $secret = PaymentSettings::secret('cloudkassir_secret');
        if ($id === null || $secret === null) {
            throw new RuntimeException('Не заданы ключи CloudKassir — «Интеграции оплаты» в админке.');
        }

        return Http::baseUrl(rtrim((string) config('livsi.cloudkassir.api_url', 'https://api.cloudpayments.ru'), '/').'/')
            ->withBasicAuth($id, $secret)
            ->acceptJson()
            ->asJson()
            ->withOptions(['verify' => TrustedCa::combined()])
            ->connectTimeout(10)
            ->timeout(30);
    }
}
