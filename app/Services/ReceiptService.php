<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Models\Payment;
use App\Models\Setting;
use App\Payments\CloudKassir;
use App\Payments\PaymentSettings;
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
 * расписание и отправляет закрывающий чек по тому же платежу: те же суммы,
 * у каждой штуки — её КИЗ. Чек уходит через ОФД в налоговую и в Честный
 * знак, и коды выводятся из оборота.
 *
 * Куда отправлять, решает платёж: оплату ЮKassa закрывает чек ЮKassa,
 * оплату Точки — чек CloudKassir (касса, подключённая к эквайрингу Точки).
 *
 * Повтор безопасен: на заказ одна запись closing и постоянный ключ повтора —
 * обрыв связи не создаст второй чек. Отказ кассы сам не повторяется: его
 * видно в заказе и в мониторинге, повтор — receipts:sync --retry=НОМЕР.
 */
class ReceiptService
{
    /** Провайдеры, чьи оплаты сайт умеет закрывать вторым чеком и возвращать. */
    public const GATEWAYS = [PaymentSettings::YOOKASSA, PaymentSettings::TOCHKA];

    public function __construct(
        private readonly YooKassaGateway $kassa,
        private readonly CloudKassir $cloud,
    ) {}

    /** Оплата заказа (ЮKassa или Точка), по которой пробивались чеки. */
    public function payment(Order $order): ?Payment
    {
        return $order->payments()->whereIn('gateway', self::GATEWAYS)->where('status', Payment::STATUS_SUCCEEDED)
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
            // Оплаты Точки ждут, пока заданы ключи CloudKassir: без них чек
            // не отправить, а отказ пришлось бы потом повторять по каждому заказу.
            ->whereHas('payments', fn ($q) => $q->whereIn('gateway', $this->cloud->isConfigured() ? self::GATEWAYS : [PaymentSettings::YOOKASSA])
                ->where('status', Payment::STATUS_SUCCEEDED))
            ->oldest('fulfillment_status_at')
            ->limit($limit)
            ->get();
    }

    /** Отправить второй чек (или вернуть уже отправленный). */
    public function sendClosing(Order $order): OrderReceipt
    {
        $payment = $this->payment($order);
        if (! $payment) {
            throw new RuntimeException('По заказу '.$order->number.' нет оплаты через сайт — второй чек не нужен или пробивается вручную.');
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
            return $this->rejected($record, 'В чеке больше 80 строк — касса столько не принимает. Пробейте чек вручную.', $order);
        }
        if ($payment->gateway === PaymentSettings::TOCHKA) {
            return $this->sendToCloudKassir($record, $order, CloudKassir::INCOME, $items, 'AdvancePayment', $total);
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

    /**
     * Чек возврата через CloudKassir — для возврата по оплате Точки, если
     * чеки возврата пробивает сайт («Интеграции оплаты»). Отдельная запись:
     * деньги уже вернулись, а чек живёт своей жизнью и может не пройти.
     *
     * @param  list<array<string, mixed>>  $items  строки в формате ReceiptBuilder
     */
    public function sendRefundReceipt(Order $order, array $items, int $amount): OrderReceipt
    {
        $previous = $order->receipt(OrderReceipt::KIND_REFUND_RECEIPT);
        if ($previous && in_array($previous->status, [OrderReceipt::PENDING, OrderReceipt::SUCCEEDED], true)) {
            return $previous;
        }
        $attempt = (int) ($previous?->response['attempt'] ?? 0) + ($previous?->status === OrderReceipt::NEW ? 0 : 1);
        $record = OrderReceipt::query()->updateOrCreate(
            ['order_id' => $order->id, 'kind' => OrderReceipt::KIND_REFUND_RECEIPT],
            ['status' => OrderReceipt::NEW, 'external_id' => null, 'error' => null, 'response' => ['attempt' => $attempt], 'done_at' => null,
                'idempotence_key' => self::key('refund-receipt/'.$order->id.'/'.$attempt), 'amount' => $amount],
        );
        if (! $this->cloud->isConfigured()) {
            return $this->rejected($record, 'Не заданы ключи CloudKassir — чек возврата пробейте в кабинете CloudKassir.', $order);
        }

        return $this->sendToCloudKassir($record, $order, CloudKassir::INCOME_RETURN, $items, 'Electronic', $amount);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sendToCloudKassir(OrderReceipt $record, Order $order, string $type, array $items, string $settlement, int $total): OrderReceipt
    {
        try {
            $body = $this->cloud->body($order, $type, $items, $settlement);
        } catch (RuntimeException $exception) {
            return $this->rejected($record, $exception->getMessage(), $order);
        }
        if (CloudKassir::total($body) !== $total) {
            return $this->rejected($record, 'Сумма чека не сходится с оплатой — пробейте чек вручную в кабинете CloudKassir.', $order);
        }
        $record->update(['payload' => $body + ['provider' => 'cloudkassir'], 'amount' => $total]);
        if ($type === CloudKassir::INCOME) {
            $this->warnMissingMarks($order);
        }

        try {
            $model = $this->cloud->send($body, $record->idempotence_key);
        } catch (ConnectionException $exception) {
            // Сеть: запись остаётся «new», повтор пойдёт с тем же X-Request-ID.
            $record->update(['error' => mb_substr('Нет связи с CloudKassir: '.$exception->getMessage(), 0, 500)]);

            return $record;
        } catch (RuntimeException $exception) {
            return $this->rejected($record, $exception->getMessage(), $order);
        }

        $record->update([
            'external_id' => (string) $model['Id'],
            'status' => OrderReceipt::PENDING,
            'response' => $model + ['attempt' => $record->response['attempt'] ?? 1],
            'sent_at' => $record->sent_at ?? now(),
            'error' => filled($model['Warning'] ?? null) ? mb_substr('CloudKassir предупреждает: '.$model['Warning'], 0, 500) : null,
        ]);

        return $record;
    }

    /** Повтор отказанного чека — с новым ключом (старый касса запомнила как отказ). */
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

    /** Узнать у кассы, чем закончился чек в обработке. */
    public function refresh(OrderReceipt $record): OrderReceipt
    {
        if (! in_array($record->kind, [OrderReceipt::KIND_CLOSING, OrderReceipt::KIND_REFUND_RECEIPT], true)
            || ! $record->external_id || $record->status !== OrderReceipt::PENDING) {
            return $record;
        }
        if (($record->payload['provider'] ?? null) === 'cloudkassir') {
            $answer = $this->cloud->status($record->external_id);

            return $this->applyCloudKassir($record, $answer['state'], $answer['message']);
        }

        return $this->applyAnswer($record, $this->kassa->receipt($record->external_id));
    }

    private function applyCloudKassir(OrderReceipt $record, string $state, ?string $message = null): OrderReceipt
    {
        $status = match ($state) {
            'Processed' => OrderReceipt::SUCCEEDED,
            'Error' => OrderReceipt::CANCELED,
            // Чек ещё не дошёл до очереди — подождём; час спустя его уже не будет.
            'NotFound' => $record->sent_at && $record->sent_at->lt(now()->subHour()) ? OrderReceipt::CANCELED : OrderReceipt::PENDING,
            default => OrderReceipt::PENDING,
        };
        // Обычно касса пробивает за секунды. Полчаса в очереди — что-то не так
        // у кассы: видно в заказе, чтобы не гадать, почему чека нет.
        $stuck = $status === OrderReceipt::PENDING && $record->sent_at && $record->sent_at->lt(now()->subMinutes(30));
        $error = match (true) {
            $status === OrderReceipt::CANCELED => mb_substr('CloudKassir не пробил чек: '.($message ?? ($state === 'NotFound' ? 'чек не найден' : 'причина в кабинете CloudKassir')), 0, 500),
            $stuck => 'CloudKassir держит чек в очереди с '.$record->sent_at->timezone('Europe/Moscow')->format('d.m H:i')
                .' — напишите в поддержку CloudKassir (номер заказа '.($record->order?->number ?? '').')',
            $status === OrderReceipt::SUCCEEDED => null,
            default => $record->error,
        };
        $record->update([
            'status' => $status,
            'response' => array_merge((array) $record->response, ['state' => $state, 'message' => $message]),
            'done_at' => $status === OrderReceipt::SUCCEEDED ? now() : null,
            'error' => $error,
        ]);
        if ($status === OrderReceipt::CANCELED) {
            Log::error('Чек не пробит в CloudKassir.', ['order' => $record->order?->number, 'kind' => $record->kind, 'receipt' => $record->external_id]);
        }

        return $record;
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
