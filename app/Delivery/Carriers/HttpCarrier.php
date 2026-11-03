<?php

namespace App\Delivery\Carriers;

use App\Delivery\Carrier;
use App\Delivery\DeliverySettings;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Phone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Общее у прямых служб: HTTP с короткими ожиданиями, пункт сдачи посылок,
 * разбивка состава заказа на штуки с кодами маркировки.
 */
abstract class HttpCarrier implements Carrier
{
    abstract protected function baseUrl(): string;

    /** Заголовки авторизации службы. @return array<string, string> */
    abstract protected function authHeaders(): array;

    protected function http(int $timeout = 15, int $connect = 4): PendingRequest
    {
        $sourceIp = config('livsi.delivery.source_ip');

        return Http::baseUrl(rtrim($this->baseUrl(), '/'))
            ->acceptJson()
            ->withHeaders($this->authHeaders())
            ->withOptions(filled($sourceIp) ? ['curl' => [CURLOPT_INTERFACE => (string) $sourceIp]] : [])
            ->connectTimeout($connect)
            ->timeout($timeout);
    }

    /** Ответ службы или исключение с её объяснением. */
    protected function ok(Response $response, string $what): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw new RuntimeException(sprintf('%s: %s — HTTP %d %s', $this->title(), $what, $response->status(),
            mb_substr($this->errorText($response), 0, 400)));
    }

    protected function errorText(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            foreach (['message', 'error', 'detail', 'description'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    return $json[$key];
                }
            }
            if (is_array($json['errors'] ?? null)) {
                return json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
            }
        }

        return (string) $response->body();
    }

    /** Откуда служба забирает посылки: номер пункта сдачи из настроек сайта. */
    protected function origin(): string
    {
        $value = DeliverySettings::origin($this->key());
        if ($value === null) {
            throw ValidationException::withMessages(['delivery_quote' => $this->title()
                .': в «Интеграциях доставки» не выбран пункт, куда сдаём посылки.']);
        }

        return $value;
    }

    protected function recipientPhone(Order $order): string
    {
        $phone = Phone::format($order->customer_phone);
        if (! $phone) {
            throw ValidationException::withMessages(['shipment' => 'В заказе нет корректного телефона получателя.']);
        }

        return $phone;
    }

    /**
     * Позиции заказа по штукам: у каждой штуки — свой код маркировки, если
     * он отсканирован при сборке. Цена за штуку — с учётом скидки.
     *
     * @return list<array{item: OrderItem, count: int, unit_price: int, mark: ?string}>
     */
    protected function units(Order $order): array
    {
        $order->loadMissing(['items.variant', 'items.marks']);
        $rows = [];
        foreach ($order->items as $item) {
            $unit = $item->quantity > 0 ? intdiv((int) $item->total, (int) $item->quantity) : 0;
            $marks = $item->marks->pluck('code')->all();
            foreach ($marks as $mark) {
                $rows[] = ['item' => $item, 'count' => 1, 'unit_price' => $unit, 'mark' => $mark];
            }
            $rest = (int) $item->quantity - count($marks);
            if ($rest > 0) {
                $rows[] = ['item' => $item, 'count' => $rest, 'unit_price' => $unit, 'mark' => null];
            }
        }

        return $rows;
    }

    /** @return array{weight: int, length: int, width: int, height: int} */
    protected function parcel(Order $order): array
    {
        $parcel = $order->delivery_quote['parcel'] ?? null;
        foreach (['weight', 'length', 'width', 'height'] as $key) {
            if (! is_int($parcel[$key] ?? null) || $parcel[$key] < 1) {
                throw ValidationException::withMessages(['shipment' => 'В заказе не сохранены вес и размеры посылки.']);
            }
        }

        return $parcel;
    }

    /** «225.7 RUB», 225.7, "225,70" → копейки. */
    protected function kopecks(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], ['.', ''], preg_replace('/[^\d.,\s]/u', '', $value) ?? '');
        }

        return is_numeric($value) && (float) $value >= 0 ? (int) round((float) $value * 100) : null;
    }

    /** Ставка НДС позиции для служб, которые её спрашивают; null — без НДС. */
    protected function vat(OrderItem $item): ?int
    {
        $rate = $item->vat_rate;

        return $rate === null ? null : (int) round((float) $rate);
    }
}
