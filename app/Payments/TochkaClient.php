<?php

namespace App\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * API Точка Банка: платёжные ссылки интернет-эквайринга и вебхуки.
 *
 * Доступ — по JWT-ключу из интернет-банка («Интеграции и API»), с правами
 * MakeAcquiringOperation, ReadAcquiringData, ReadCustomerData и
 * ManageWebhookData.
 *
 * Ключа повтора у Точки нет: повтор создания ссылки дал бы вторую ссылку,
 * повтор возврата — второй возврат. Поэтому сам клиент повторяет только
 * чтение; что делать с оборванной записью, решает тот, кто её вызвал.
 */
class TochkaClient
{
    public function isConfigured(): bool
    {
        return PaymentSettings::secret('tochka_jwt') !== null && PaymentSettings::customerCode() !== null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createPayment(array $data): array
    {
        return $this->data($this->client()->post('acquiring/v1.0/payments_with_receipt', ['Data' => $data]), 'создание платежа');
    }

    /** Операция по платёжной ссылке: статус, способ оплаты, возвраты. @return array<string, mixed> */
    public function payment(string $operationId): array
    {
        $data = $this->data($this->reading()->get('acquiring/v1.0/payments/'.rawurlencode($operationId)), 'статус платежа');
        $operation = $data['Operation'][0] ?? null;
        if (! is_array($operation)) {
            throw new RuntimeException('Точка не вернула платёж '.$operationId.'.');
        }

        return $operation;
    }

    /** Возврат денег (полный или частичный). Сумма в копейках. @return array<string, mixed> */
    public function refund(string $operationId, int $amount): array
    {
        return $this->data($this->client()->post('acquiring/v1.0/payments/'.rawurlencode($operationId).'/refund', [
            'Data' => ['amount' => round($amount / 100, 2)],
        ]), 'возврат');
    }

    /** Торговые точки: статус подключения эквайринга. @return list<array<string, mixed>> */
    public function retailers(?string $customerCode = null): array
    {
        $data = $this->data($this->reading()->get('acquiring/v1.0/retailers', [
            'customerCode' => $customerCode ?? PaymentSettings::customerCode(),
        ]), 'торговые точки');

        return array_values((array) ($data['Retailer'] ?? []));
    }

    /** Компании, к которым у ключа есть доступ (customerCode). @return list<array<string, mixed>> */
    public function customers(): array
    {
        $data = $this->data($this->reading()->get('open-banking/v1.0/customers'), 'список компаний');

        return array_values((array) ($data['Customer'] ?? []));
    }

    /**
     * Подписка на уведомления об оплате по ссылкам. Точка сразу шлёт на адрес
     * пробное уведомление и подписывает, только если сайт ответил 200.
     *
     * @return array<string, mixed>
     */
    public function subscribe(string $clientId, string $url): array
    {
        $body = ['webhooksList' => ['acquiringInternetPayment'], 'url' => $url];
        $path = 'webhook/v1.0/'.rawurlencode($clientId);
        $response = $this->client()->put($path, $body);
        // Подписка уже есть — меняем её адрес и события (у Точки это другой метод).
        if ($response->clientError() && ! in_array($response->status(), [401, 403], true)) {
            $response = $this->client()->post($path, $body);
        }

        return $this->data($response, 'подписка на уведомления');
    }

    /** @return array<string, mixed> */
    public function webhooks(string $clientId): array
    {
        return $this->data($this->reading()->get('webhook/v1.0/'.rawurlencode($clientId)), 'подписки на уведомления');
    }

    /** Data из ответа или исключение с объяснением Точки. @return array<string, mixed> */
    private function data(Response $response, string $what): array
    {
        $json = $response->json();
        if ($response->successful() && is_array($json)) {
            return (array) ($json['Data'] ?? []);
        }
        $json = is_array($json) ? $json : [];
        $details = [];
        foreach ((array) ($json['Errors'] ?? []) as $error) {
            if (is_array($error) && filled($error['message'] ?? null)) {
                $details[] = trim((string) $error['message']);
            }
        }
        $text = trim(implode('; ', array_unique(array_filter([trim((string) ($json['message'] ?? '')), ...$details]))));
        if ($response->status() === 401 || $response->status() === 403) {
            $text = 'ключ не принят или у него нет нужных прав'.($text !== '' ? ' ('.$text.')' : '');
        }
        Log::warning('Точка отказала: '.$what, ['status' => $response->status(), 'message' => mb_substr($text, 0, 300)]);

        throw new RuntimeException('Точка, '.$what.': '.($text !== '' ? $text : 'HTTP '.$response->status()));
    }

    private function client(): PendingRequest
    {
        $jwt = PaymentSettings::secret('tochka_jwt');
        if ($jwt === null) {
            throw new RuntimeException('Не задан JWT-ключ Точки — «Интеграции оплаты» в админке.');
        }

        return Http::baseUrl(rtrim((string) config('livsi.tochka.api_url', 'https://enter.tochka.com/uapi'), '/').'/')
            ->withToken($jwt)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30);
    }

    /** Чтение безопасно повторить: оно ничего не меняет у банка. */
    private function reading(): PendingRequest
    {
        return $this->client()->retry(2, 400, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
