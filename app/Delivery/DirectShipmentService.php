<?php

namespace App\Delivery;

use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Services\DeliverySelection;
use App\Services\FulfillmentService;
use App\Support\Fulfillment;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Отправления напрямую у службы: создать, проверить, этикетка, отмена,
 * подтягивание статуса.
 *
 * Права здесь не проверяются — это делают те, кто вызывает: кнопка в
 * админке (только администратор) и API для livsipro (по токену).
 *
 * Главное правило — не создать у службы два отправления на один заказ.
 * Перед запросом заказ помечается «creating» под блокировкой. Если ответ
 * службы потерялся, заказ остаётся «unknown», и повторное нажатие сначала
 * ищет отправление у службы по номеру заказа, а не создаёт новое.
 */
class DirectShipmentService
{
    public function __construct(
        private readonly DeliveryGateway $gateway,
        private readonly FulfillmentService $fulfillment,
    ) {}

    public static function isDirectOrder(Order $order): bool
    {
        return ($order->delivery_quote['direct'] ?? false) === true
            || in_array($order->shipment_provider, DeliveryGateway::PROVIDERS, true);
    }

    /** @param array<string, mixed> $sender */
    public function create(Order $record, array $sender): Order
    {
        $sender = $this->sender($sender);
        $carrier = $this->carrierFor($record);

        $order = DB::transaction(function () use ($record, $sender, $carrier): ?Order {
            $order = Order::query()->lockForUpdate()->findOrFail($record->id);
            if (! $order->isPaid()) {
                $this->fail('Отправление можно создать только для оплаченного заказа.');
            }
            if ($order->shipment_external_id) {
                return null;
            }
            if (in_array($order->shipment_state, ['creating', 'unknown'], true)) {
                $this->fail('Запрос уже отправлен. Нажмите «Проверить отправление», чтобы получить результат без повторной заявки.');
            }
            $order->forceFill([
                'shipment_provider' => $carrier->key(), 'shipment_state' => 'creating',
                'shipment_requested_at' => now(), 'shipment_error' => null, 'shipment_sender' => $sender,
            ])->save();

            return $order;
        });
        if ($order === null) {
            return $record->fresh();
        }

        try {
            $result = $carrier->createShipment($order->fresh('items.variant'), $sender);
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();
            $order->forceFill(['shipment_state' => 'failed', 'shipment_error' => mb_substr($message, 0, 255)])->save();
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Ответ службы на создание отправления не получен.', [
                'order' => $order->number, 'provider' => $carrier->key(),
                'error_type' => $exception::class, 'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
            $this->unknown($order);
        }

        return $this->store($order, $carrier, $result, 'Создано у службы');
    }

    /** Узнать, что с отправлением: найти потерянное или обновить статус. */
    public function refresh(Order $record): Order
    {
        $order = $record->fresh();
        $carrier = $this->carrierFor($order);
        if (! $order->shipment_external_id) {
            if (! in_array($order->shipment_state, ['creating', 'unknown', 'failed'], true)) {
                $this->fail('Заявка на отправление ещё не отправлялась.');
            }
            try {
                $found = $carrier->findShipment($order);
            } catch (Throwable $exception) {
                $this->fail('Не удалось связаться со службой. Повторите проверку позже; новая заявка не отправлялась.');
            }
            if ($found === null) {
                // Служба заказа не знает — значит, он не создан, можно отправить заново.
                $order->forceFill(['shipment_state' => 'failed',
                    'shipment_error' => 'Служба не нашла заявку по номеру заказа. Создайте отправление заново.'])->save();
                $this->fail('Служба не нашла заявку по номеру '.$order->number.'. Создайте отправление заново.');
            }
            $order = $this->store($order, $carrier, $found, 'Найдено у службы');
        }

        return $this->sync($order);
    }

    /**
     * Подтянуть статус у службы и, если он однозначен, перевести статус
     * доставки заказа. Статус двигается только вперёд: опоздавший ответ
     * службы не вернёт «Получен» обратно в «В пути». Возврат — из любого.
     */
    public function sync(Order $order): Order
    {
        $carrier = $this->carrierFor($order);
        if (method_exists($carrier, 'advance')) {
            // Ozon: передать коды и отметить сборку, когда заказ собран в цехе
            $carrier->advance($order);
            $order->refresh();
        }
        $info = $carrier->shipmentInfo($order);
        $order->forceFill([
            'shipment_status_code' => $this->cut($info['status_code'] ?? null, 60),
            'shipment_status_name' => $this->cut($info['status_name'] ?? null, 255) ?? $order->shipment_status_name,
            'shipment_tracking_number' => $this->cut($info['tracking'] ?? null, 150) ?? $order->shipment_tracking_number,
            'shipment_data' => array_merge($order->shipment_data ?? [], $info['data'] ?? []),
            'shipment_synced_at' => now(),
        ])->save();

        $target = $info['fulfillment'] ?? null;
        if ($target && Fulfillment::canChange($order) && $this->isForward(Fulfillment::current($order), $target)) {
            $order = $this->fulfillment->set($order, $target, $info['status_name'] ?? null, $this->source($carrier->key()));
        }

        return $order->fresh();
    }

    public function label(Order $order, string $size = '75x120'): string
    {
        if (! $order->shipment_external_id) {
            $this->fail('Сначала создайте отправление — этикетку выдаёт служба.');
        }
        $pdf = $this->carrierFor($order)->label($order, preg_match('/^\d{2,3}x\d{2,3}$/', $size) ? $size : '75x120');
        if (! str_starts_with($pdf, '%PDF')) {
            $this->fail('Служба ещё не подготовила этикетку. Повторите через минуту.');
        }

        return $pdf;
    }

    public function cancel(Order $order): Order
    {
        if (! $order->shipment_external_id) {
            $this->fail('Отправление у службы не создано — отменять нечего.');
        }
        $this->carrierFor($order)->cancel($order);
        $order->forceFill(['shipment_state' => 'cancelled', 'shipment_status_name' => 'Отменено'])->save();

        return $order;
    }

    private function carrierFor(Order $order): Carrier
    {
        $provider = $order->shipment_provider ?: ($order->delivery_quote['provider'] ?? null);
        if (! in_array($provider, DeliveryGateway::PROVIDERS, true)) {
            $this->fail('Для заказа нет поддерживаемой службы доставки.');
        }
        if ($order->shipment_provider === null && DeliverySelection::providerKey($order->delivery_method) !== $provider) {
            $this->fail('Служба в заказе не совпадает со способом доставки.');
        }
        $carrier = $this->gateway->carrier($provider);
        if (! $carrier->isConfigured()) {
            $this->fail($carrier->title().': не заданы ключи доступа в настройках сервера.');
        }

        return $carrier;
    }

    /** @param array{external_id: string, tracking: ?string, status_code: ?string, status_name: ?string, data: array} $result */
    private function store(Order $order, Carrier $carrier, array $result, string $fallbackName): Order
    {
        $id = $this->cut($result['external_id'] ?? null, 100);
        if ($id === null) {
            $this->unknown($order);
        }
        $order->forceFill([
            'shipment_provider' => $carrier->key(), 'shipment_external_id' => $id, 'shipment_state' => 'created',
            'shipment_status_code' => $this->cut($result['status_code'] ?? null, 60),
            'shipment_status_name' => $this->cut($result['status_name'] ?? null, 255) ?? $fallbackName,
            'shipment_tracking_number' => $this->cut($result['tracking'] ?? null, 150),
            'shipment_data' => $result['data'] ?? [],
            'shipment_created_at' => $order->shipment_created_at ?? now(), 'shipment_error' => null,
        ])->save();

        return $order;
    }

    /**
     * @param  array<string, mixed>  $sender
     * @return array{name: string, phone: string, email: ?string}
     */
    private function sender(array $sender): array
    {
        $sender = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $sender);
        $sender = Validator::make($sender, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ])->validate();
        $phone = Phone::format($sender['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Укажите полный российский телефон отправителя.']);
        }

        return ['name' => $sender['name'], 'phone' => $phone, 'email' => $sender['email'] ?? null];
    }

    private function isForward(string $current, string $target): bool
    {
        if ($target === $current) {
            return false;
        }
        if ($target === Fulfillment::RETURNED) {
            return true;
        }
        $rank = array_flip([Fulfillment::AWAITING, Fulfillment::ASSEMBLED, Fulfillment::SHIPPED,
            Fulfillment::AT_PICKUP, Fulfillment::DELIVERED]);

        return isset($rank[$current], $rank[$target]) && $rank[$target] > $rank[$current];
    }

    private function source(string $provider): string
    {
        return match ($provider) {
            'cdek' => OrderFulfillmentEvent::SOURCE_CDEK,
            'yataxi' => OrderFulfillmentEvent::SOURCE_YANDEX,
            'ozondel' => OrderFulfillmentEvent::SOURCE_OZON,
        };
    }

    private function cut(mixed $value, int $length): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, $length) : null;
    }

    private function unknown(Order $order): never
    {
        $message = 'Результат создания не подтверждён. Нажмите «Проверить отправление»; повторная заявка не будет отправлена.';
        Order::query()->whereKey($order->id)->whereNull('shipment_external_id')
            ->update(['shipment_state' => 'unknown', 'shipment_error' => $message]);
        $this->fail($message);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['shipment' => $message]);
    }
}
