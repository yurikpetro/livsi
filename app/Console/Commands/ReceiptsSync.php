<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderReceipt;
use App\Payments\ReceiptBuilder;
use App\Services\ReceiptService;
use App\Services\RefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вторые чеки и возвраты — раз в пять минут по расписанию.
 *
 *   php artisan receipts:sync                 — отправить вторые чеки полученным заказам
 *                                               и узнать итог чеков и возвратов в обработке
 *   php artisan receipts:sync --retry=LV-123  — повторить отказанный второй чек
 *   php artisan receipts:sync --show=LV-123   — что уйдёт во втором чеке (ничего не отправляет)
 */
class ReceiptsSync extends Command
{
    protected $signature = 'receipts:sync
        {--retry= : номер заказа, по которому повторить второй чек}
        {--show= : номер заказа — показать, что уйдёт во втором чеке, ничего не отправляя}';

    protected $description = 'Вторые чеки (полный расчёт с КИЗ) и статусы возвратов ЮKassa';

    public function handle(ReceiptService $receipts, RefundService $refunds): int
    {
        if (filled($this->option('show'))) {
            return $this->show($receipts, (string) $this->option('show'));
        }
        if (filled($this->option('retry'))) {
            $order = Order::query()->where('number', (string) $this->option('retry'))->first();
            if (! $order) {
                $this->error('Нет такого заказа.');

                return self::FAILURE;
            }
            $record = $receipts->retry($order);
            $this->line($order->number.': второй чек — '.$record->statusLabel().($record->error ? ' ('.$record->error.')' : ''));

            return $record->status === OrderReceipt::CANCELED ? self::FAILURE : self::SUCCESS;
        }

        $sent = 0;
        $just = [];
        foreach ($receipts->due() as $order) {
            try {
                $record = $receipts->sendClosing($order);
                $just[] = $record->id;
                $sent++;
                $this->line($order->number.': второй чек — '.$record->statusLabel());
            } catch (Throwable $exception) {
                Log::error('Второй чек не отправлен.', ['order' => $order->number, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            }
        }

        // Итог узнаём со следующего запуска: только что отправленным ЮKassa нужно время.
        $pending = OrderReceipt::query()->where('status', OrderReceipt::PENDING)->whereNotNull('external_id')
            ->whereNotIn('id', $just)->with('order')->oldest('id')->limit(100)->get();
        foreach ($pending as $record) {
            try {
                $record->kind === OrderReceipt::KIND_REFUND ? $refunds->refresh($record) : $receipts->refresh($record);
            } catch (Throwable $exception) {
                Log::warning('Не удалось узнать итог у ЮKassa.', ['order' => $record->order?->number, 'kind' => $record->kind,
                    'error' => mb_substr($exception->getMessage(), 0, 300)]);
            }
        }

        if ($sent) {
            $this->info('Вторых чеков отправлено: '.$sent);
        }

        return self::SUCCESS;
    }

    /** Состав второго чека — как его увидит ЮKassa, только без отправки. */
    private function show(ReceiptService $receipts, string $number): int
    {
        $order = Order::query()->where('number', $number)->with('items.marks', 'items.variant')->first();
        if (! $order) {
            $this->error('Нет такого заказа.');

            return self::FAILURE;
        }
        $payment = $receipts->payment($order);
        $items = (new ReceiptBuilder($order))->closingItems();
        $total = ReceiptBuilder::sum($items);

        $this->line('Заказ '.$order->number.' · второй чек «полный расчёт» · '.($receipts->enabled() ? 'включён' : 'выключен в настройках'));
        $this->table(['Строка', 'Кол-во', 'Сумма', 'Признак', 'Код маркировки'], array_map(fn (array $item): array => [
            mb_strimwidth((string) $item['description'], 0, 44, '…'),
            $item['quantity'],
            $item['amount']['value'],
            $item['payment_subject'].' / '.$item['payment_mode'],
            isset($item['mark_code_info']['gs_1m'])
                ? mb_strimwidth(str_replace("\x1D", '<GS>', $item['mark_code_info']['gs_1m']), 0, 40, '…')
                : '—',
        ], $items));
        $this->line('Итого в чеке: '.number_format($total / 100, 2, '.', ' ').' ₽ · оплата ЮKassa: '
            .($payment ? number_format($payment->amount / 100, 2, '.', ' ').' ₽ ('.$payment->external_id.')' : 'нет'));
        if (! $payment || $total !== (int) $payment->amount) {
            $this->warn('Сумма не совпадает с оплатой — такой чек ЮKassa не примет.');

            return self::FAILURE;
        }
        $record = $order->receipt(OrderReceipt::KIND_CLOSING);
        $this->line('Сейчас: '.($record ? $record->statusLabel().($record->error ? ' ('.$record->error.')' : '') : 'ещё не отправлялся'));

        return self::SUCCESS;
    }
}
