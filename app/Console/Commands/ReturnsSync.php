<?php

namespace App\Console\Commands;

use App\Models\OrderReturn;
use App\Services\ReturnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Где посылки возвратов — раз в 30 минут. Пришла в наш пункт СДЭК —
 * сообщение в Telegram; номер накладной появился позже — письмо покупателю.
 */
class ReturnsSync extends Command
{
    protected $signature = 'returns:sync';

    protected $description = 'Обновить статусы обратных накладных СДЭК';

    public function handle(ReturnService $returns): int
    {
        $done = $failed = 0;
        $query = OrderReturn::query()
            ->where('status', OrderReturn::APPROVED)
            ->where('method', OrderReturn::METHOD_CDEK)
            ->whereNotNull('shipment_uuid')
            ->where('created_at', '>=', now()->subDays(60))
            ->orderBy('shipment_checked_at');
        foreach ($query->limit(200)->get() as $return) {
            try {
                $return = $returns->refreshWaybill($return);
                $done++;
                $this->line($return->number.': '.($return->shipment_status_name ?? '—'));
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('Статус возврата не обновлён.', ['return' => $return->number, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
            }
        }
        $this->info("Обновлено: $done, с ошибкой: $failed.");

        return self::SUCCESS;
    }
}
