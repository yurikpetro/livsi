<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Services\DeliverySelection;
use Carbon\CarbonInterface;
use Filament\Support\Colors\Color;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Статус исполнения заказа: сборка → передача в доставку → выдача.
 *
 * Отдельно от финансового статуса (`orders.status`) и от технического
 * состояния заявки в ApiShip (`orders.shipment_state`): оплата говорит
 * «деньги получены», ApiShip — «служба приняла заявку», а этот статус
 * отвечает на вопрос покупателя «где мой заказ».
 *
 * Пока статус ставит менеджер вручную. Когда появятся статусы перевозчика,
 * их можно сопоставить с этими же значениями и писать через
 * FulfillmentService с source = 'apiship' — покупатель увидит то же самое.
 *
 * Пустое значение у оплаченного заказа означает «ждёт сборки»: так не нужно
 * трогать оплату и заполнять старые заказы задним числом.
 */
final class Fulfillment
{
    public const AWAITING = 'awaiting';

    public const ASSEMBLED = 'assembled';

    public const SHIPPED = 'shipped';

    public const AT_PICKUP = 'at_pickup';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const TIMEZONE = 'Europe/Moscow';

    /** Подписи для админки. */
    public static function statuses(): array
    {
        return [
            self::AWAITING => 'Ждёт сборки',
            self::ASSEMBLED => 'Собран',
            self::SHIPPED => 'Передан в доставку',
            self::AT_PICKUP => 'В пункте выдачи',
            self::DELIVERED => 'Получен',
            self::RETURNED => 'Возврат отправителю',
        ];
    }

    /** Короткие подписи для кнопок в таблице. */
    public static function shortLabels(): array
    {
        return [
            self::AWAITING => 'Ждёт сборки',
            self::ASSEMBLED => 'Собран',
            self::SHIPPED => 'Передан',
            self::AT_PICKUP => 'В ПВЗ',
            self::DELIVERED => 'Получен',
            self::RETURNED => 'Возврат',
        ];
    }

    public static function label(?string $status): string
    {
        return $status ? (self::statuses()[$status] ?? $status) : '—';
    }

    public static function shortLabel(?string $status): string
    {
        return $status ? (self::shortLabels()[$status] ?? $status) : '—';
    }

    /** Цвет бейджа Filament. */
    public static function color(?string $status): string|array
    {
        return match ($status) {
            self::ASSEMBLED => 'info',
            self::SHIPPED => Color::Violet,
            self::AT_PICKUP => 'warning',
            self::DELIVERED => 'success',
            self::RETURNED => 'danger',
            default => 'gray',
        };
    }

    /** Статус ставится оплаченному заказу; возвращённому — чтобы отметить возврат посылки. */
    public static function canChange(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_PAID, Order::STATUS_REFUNDED], true);
    }

    /** Текущий статус с учётом умолчания; null — заказ ещё не дошёл до сборки. */
    public static function current(Order $order): ?string
    {
        $stored = $order->getAttribute('fulfillment_status');

        if (is_string($stored) && array_key_exists($stored, self::statuses())) {
            return $stored;
        }

        return $order->status === Order::STATUS_PAID ? self::AWAITING : null;
    }

    public static function isCourier(Order $order): bool
    {
        return $order->delivery_method === DeliverySelection::STAVROPOL_COURIER;
    }

    /** Обычный путь заказа. У курьера нет шага «в пункте выдачи». */
    public static function flow(Order $order): array
    {
        return self::isCourier($order)
            ? [self::AWAITING, self::ASSEMBLED, self::SHIPPED, self::DELIVERED]
            : [self::AWAITING, self::ASSEMBLED, self::SHIPPED, self::AT_PICKUP, self::DELIVERED];
    }

    /** Следующий шаг по обычному пути или null, если идти дальше некуда. */
    public static function next(Order $order): ?string
    {
        if (! self::canChange($order)) {
            return null;
        }

        $flow = self::flow($order);
        $index = array_search(self::current($order), $flow, true);

        return $index === false || $index >= count($flow) - 1 ? null : $flow[$index + 1];
    }

    /** Варианты для ручного выбора: путь этого заказа плюс возврат. */
    public static function options(Order $order): array
    {
        return collect([...self::flow($order), self::RETURNED])
            ->mapWithKeys(fn (string $status): array => [$status => self::label($status)])
            ->all();
    }

    /** Когда поставлен текущий статус. */
    public static function since(Order $order): ?CarbonInterface
    {
        $value = $order->getAttribute('fulfillment_status_at');

        if ($value) {
            return Carbon::parse($value);
        }

        return self::current($order) === self::AWAITING ? $order->paid_at : null;
    }

    public static function moscow(?CarbonInterface $time, string $format = 'd.m H:i'): ?string
    {
        return $time?->copy()->timezone(self::TIMEZONE)->format($format);
    }

    /** @return Collection<int, OrderFulfillmentEvent> */
    public static function events(Order $order): Collection
    {
        if (! $order->exists) {
            return collect();
        }

        return OrderFulfillmentEvent::query()
            ->with('user:id,name')
            ->where('order_id', $order->id)
            ->latest('id')
            ->get();
    }

    /**
     * Шаги для шкалы: что пройдено и когда.
     *
     * @return array<int, array{status: string, label: string, customer_label: string, reached: bool, current: bool, time: ?CarbonInterface}>
     */
    public static function steps(Order $order, ?Collection $events = null): array
    {
        $events ??= self::events($order);
        $current = self::current($order);
        $flow = self::flow($order);
        $position = array_search($current, $flow, true);

        // Время шага — последний раз, когда заказ в него переводили. Записи
        // журнала с тем же статусом подряд (возврат денег, приёмка возврата)
        // — не переход: время получения от них не сдвигается.
        $times = collect();
        $previous = null;
        foreach ($events->sortBy('id') as $event) {
            if ($event->status !== $previous) {
                $times[$event->status] = $event->created_at;
            }
            $previous = $event->status;
        }

        $steps = collect($flow)->map(function (string $status, int $index) use ($order, $position, $times): array {
            $reached = $position !== false && $index <= $position;

            return [
                'status' => $status,
                'label' => self::label($status),
                'customer_label' => $status === self::AWAITING ? 'Оплачен' : self::label($status),
                'reached' => $reached,
                'current' => $position !== false && $index === $position,
                'time' => $reached ? ($times[$status] ?? ($status === self::AWAITING ? $order->paid_at : null)) : null,
            ];
        })->all();

        // Пройденный шаг без своей отметки (служба сообщила «передан», а «Собран»
        // не нажимали) случился не позже следующего шага — показываем его время,
        // а не прочерк.
        for ($i = count($steps) - 2; $i >= 0; $i--) {
            if ($steps[$i]['reached'] && $steps[$i]['time'] === null && $steps[$i + 1]['time'] !== null) {
                $steps[$i]['time'] = $steps[$i + 1]['time'];
            }
        }

        return $steps;
    }

    /**
     * Что видит покупатель.
     *
     * @return array{title: string, text: string}|null
     */
    public static function forCustomer(Order $order): ?array
    {
        // Деньги вернули — покупатель видит «Заказ отменён» вверху страницы,
        // ход доставки ему больше не нужен.
        if ($order->status === Order::STATUS_REFUNDED) {
            return null;
        }
        $courier = self::isCourier($order);

        return match (self::current($order)) {
            self::AWAITING => [
                'title' => 'Готовим к сборке',
                'text' => 'Оплата получена, заказ в очереди на сборку. Как только соберём, статус обновится на этой странице.',
            ],
            self::ASSEMBLED => [
                'title' => 'Заказ собран',
                'text' => $courier
                    ? 'Собрали и упаковали. Скоро передадим курьеру.'
                    : 'Собрали и упаковали. Скоро передадим в службу доставки.',
            ],
            self::SHIPPED => [
                'title' => 'Передан в доставку',
                'text' => $courier
                    ? 'Заказ у курьера и едет к вам.'
                    : 'Заказ у службы доставки и едет к вам. Когда он приедет в пункт выдачи, статус обновится.',
            ],
            self::AT_PICKUP => [
                'title' => 'Ждёт вас в пункте выдачи',
                'text' => 'Заберите заказ, пока идёт срок хранения. Срок указан в уведомлении службы доставки.',
            ],
            self::DELIVERED => [
                'title' => 'Заказ получен',
                'text' => 'Спасибо, что выбрали LIVSI! Будем рады, если расскажете, как вам.',
            ],
            self::RETURNED => [
                'title' => 'Заказ вернулся к нам',
                'text' => 'Посылку не забрали, и служба доставки вернула её отправителю. Если это ошибка, напишите нам.',
            ],
            default => null,
        };
    }
}
