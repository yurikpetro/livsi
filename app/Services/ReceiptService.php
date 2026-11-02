<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Models\Payment;
use App\Models\Setting;
use App\Payments\ReceiptBuilder;
use App\Payments\YooKassaGateway;
use App\Support\Fulfillment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Второй чек — «полный расчёт» (зачёт предоплаты) с КИЗ.
 *
 * Первый чек «предоплата 100%» уходит вместе с оплатой. Когда покупатель
 * забрал заказ (статус «Получен» — от службы или вручную), сюда приходит
 * расписание и отправляет в ЮKassa закрывающий чек по тому же платежу:
 * те же суммы, у каждой штуки — её КИЗ. Чек уходит через ОФД в налоговую и
 * в Честный знак, и коды выводятся из оборота.
 *
 * Повтор безопасен: на заказ одна запись closing и постоянный ключ повтора —
 * обрыв связи не создаст второй чек. Отказ ЮKassa сам не повторяется: его
 * видно в заказе и в мониторинге, повтор — receipts:sync --retry=НОМЕР.
 */
class ReceiptService
{
    public function __construct(private readonly YooKassaGateway $kassa) {}

    /** Оплата заказа в ЮKassa, по которой пробивались чеки. */
    public function payment(Order $order): ?Payment
    {
        return $order->payments()->where('gateway', 'yookassa')->where('status', Payment::STATUS_SUCCEEDED)
            ->whereNotNull('external_id')->latest('id')->first();
    }

    /** Включён ли второй чек («Настройки сайта» → «Налоги и чеки»). */
    public function enabled(): bool
    {
        return (bool) Setting::get('closing_receipts_enabled', false);
    }

    /**
     * С какого момента закрываем заказы вторым чеком. Ставится при первом
     * запуске: заказы, полученные до появления этой функции, не трогаем.
     */
    public function since(): Carbon
    {
        $value = Setting::get('closing_receipts_since');
        if (! is_string($value) || $value === '') {
            $value = now()->toIso8601String();
            Setting::put('closing_receipts_since', $value);
        }

        return Carbon::parse($value);
    }

    /** Заказы, которым пора отправить второй чек. @return Collection<int, Order> */
    public function due(int $limit = 50): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        return Order::query()
            ->where('status', Order::STATUS_PAID)
            ->where('fulfillment_status', Fulfillment::DELIVERED)
            ->where('fulfillment_status_at', '>=', $this->since())
            ->whereDoesntHave('receipts', fn ($q) => $q->where('kind', OrderReceipt::KIND_CLOSING)
                ->whereIn('status', [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED, OrderReceipt::CANCELED]))
            ->whereDoesntHave('receipts', fn ($q) => $q->where('kind', OrderReceipt::KIND_REFUND)
                ->whereIn('status', [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED]))
            ->whereHas('payments', fn ($q) => $q->where('gateway', 'yookassa')->where('status', Payment::STATUS_SUCCEEDED))
            ->oldest('fulfillment_status_at')
            ->limit($limit)
            ->get();
    }

    /** Отправить второй чек (или вернуть уже отправленный). */
    public function sendClosing(Order $order): OrderReceipt
    {
        $payment = $this->payment($order);
        if (! $payment) {
            throw new RuntimeException('По заказу '.$order->number.' нет оплаты ЮKassa — второй чек не нужен или пробивается вручную.');
        }

        $record = DB::transaction(function () use ($order): OrderReceipt {
            Order::query()->lockForUpdate()->findOrFail($order->id);

            return OrderReceipt::query()->firstOrCreate(
                ['order_id' => $order->id, 'kind' => OrderReceipt::KIND_CLOSING],
                ['status' => OrderReceipt::NEW, 'idempotence_key' => self::key('closing/'.$order->id.'/1')],
            );
        });
        if ($record->status !== OrderReceipt::NEW) {
            return $record;
        }

        $order->load('items.marks', 'items.variant');
        $builder = new ReceiptBuilder($order);
        $items = $builder->closingItems();
        $total = ReceiptBuilder::sum($items);
        if ($total !== (int) $payment->amount) {
            return $this->rejected($record, sprintf('Сумма второго чека %s ₽ не совпадает с оплатой %s ₽ — пробейте чек вручную.',
                number_format($total / 100, 2, ',', ' '), number_format($payment->amount / 100, 2, ',', ' ')), $order);
        }
        if (count($items) > 80) {
            return $this->rejected($record, 'В чеке больше 80 строк — ЮKassa столько не принимает. Пробейте чек вручную.', $order);
        }

        $body = [
            'type' => 'payment',
            'payment_id' => $payment->external_id,
            'send' => true,
            'customer' => $builder->customer(),
            'items' => $items,
            'settlements' => [['type' => 'prepayment', 'amount' => ['value' => number_format($total / 100, 2, '.', ''), 'currency' => 'RUB']]],
            'internet' => true,
        ];
        $record->update(['payload' => $body, 'amount' => $total]);
        $this->warnMissingMarks($order);

        try {
            $answer = $this->kassa->createReceipt($body, $record->idempotence_key);
        } catch (ConnectionException $exception) {
            // Сеть: запись остаётся «new», расписание повторит с тем же ключом.
            $record->update(['error' => mb_substr('Нет связи с ЮKassa: '.$exception->getMessage(), 0, 500)]);

            return $record;
        } catch (RuntimeException $exception) {
            return $this->rejected($record, $exception->getMessage(), $order);
        }

        return $this->applyAnswer($record, $answer);
    }

    /** Повтор отказанного чека — с новым ключом (старый ЮKassa запомнила как отказ). */
    public function retry(Order $order): OrderReceipt
    {
        $record = $order->receipt(OrderReceipt::KIND_CLOSING);
        if ($record && $record->status === OrderReceipt::SUCCEEDED) {
            return $record;
        }
        if ($record) {
            $attempt = (int) ($record->response['attempt'] ?? 1) + 1;
            $record->update(['status' => OrderReceipt::NEW, 'error' => null, 'external_id' => null,
                'idempotence_key' => self::key('closing/'.$order->id.'/'.$attempt), 'response' => ['attempt' => $attempt]]);
        }

        return $this->sendClosing($order);
    }

    /** Узнать у ЮKassa, чем закончился чек в обработке. */
    public function refresh(OrderReceipt $record): OrderReceipt
    {
        if ($record->kind !== OrderReceipt::KIND_CLOSING || ! $record->external_id || $record->status !== OrderReceipt::PENDING) {
            return $record;
        }

        return $this->applyAnswer($record, $this->kassa->receipt($record->external_id));
    }

    /** @param array<string, mixed> $answer */
    private function applyAnswer(OrderReceipt $record, array $answer): OrderReceipt
    {
        $status = (string) ($answer['status'] ?? OrderReceipt::PENDING);
        $record->update([
            'external_id' => $answer['id'] ?? $record->external_id,
            'status' => $status,
            'response' => $answer + ['attempt' => $record->response['attempt'] ?? 1],
            'sent_at' => $record->sent_at ?? now(),
            'done_at' => $status === OrderReceipt::SUCCEEDED ? now() : null,
            'error' => $status === OrderReceipt::CANCELED ? 'ЮKassa не зарегистрировала чек — проверьте в кабинете ЮKassa' : null,
        ]);
        if ($status === OrderReceipt::CANCELED) {
            Log::error('Второй чек не зарегистрирован в ЮKassa.', ['order' => $record->order?->number, 'receipt' => $record->external_id]);
        }

        return $record;
    }

    private function rejected(OrderReceipt $record, string $message, Order $order): OrderReceipt
    {
        $record->update(['status' => OrderReceipt::CANCELED, 'error' => mb_substr($message, 0, 500)]);
        Log::error('Второй чек не отправлен.', ['order' => $order->number, 'error' => mb_substr($message, 0, 300)]);

        return $record;
    }

    /** Штуки без КИЗ пойдут обычным товаром — их коды сами из оборота не выйдут. */
    private function warnMissingMarks(Order $order): void
    {
        $missing = [];
        foreach ($order->items as $item) {
            $barcode = preg_replace('/\D/', '', (string) $item->variant?->barcode);
            if ($barcode !== '' && $item->marks->count() < (int) $item->quantity) {
                $missing[] = $item->label().': '.$item->marks->count().' из '.$item->quantity;
            }
        }
        if ($missing) {
            Log::warning('Второй чек: КИЗ отсканированы не по всем штукам — коды этих штук выведите из оборота вручную.',
                ['order' => $order->number, 'items' => $missing]);
        }
    }

    /** Ключ повтора — UUID из постоянной строки: один и тот же для одной операции. */
    public static function key(string $seed): string
    {
        $hash = sha1('livsi.shop/receipts/'.$seed);

        return sprintf('%s-%s-5%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80).substr($hash, 18, 2), substr($hash, 20, 12));
    }
}
