<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderReceipt;
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
 */
class ReceiptsSync extends Command
{
    protected $signature = 'receipts:sync {--retry= : номер заказа, по которому повторить второй чек}';

    protected $description = 'Вторые чеки (полный расчёт с КИЗ) и статусы возвратов ЮKassa';

    public function handle(ReceiptService $receipts, RefundService $refunds): int
    {
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
}
