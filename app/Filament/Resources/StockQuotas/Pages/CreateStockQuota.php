<?php

namespace App\Filament\Resources\StockQuotas\Pages;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use App\Models\ProductVariant;
use App\Models\StockQuota;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStockQuota extends CreateRecord
{
    protected static string $resource = StockQuotaResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $variant = ProductVariant::query()->whereKey($data['product_variant_id'])->lockForUpdate()->firstOrFail();
        if ($variant->quota()->exists()) {
            throw ValidationException::withMessages(['data.product_variant_id' => 'Остаток этого варианта уже настроен. Измените его в списке.']);
        }
        $quota = StockQuota::create([
            'product_variant_id' => $variant->id,
            'allocated' => (int) $data['allocated'],
            'reserved' => 0,
            'sold' => 0,
            'low_threshold' => (int) $data['low_threshold'],
            'allocated_at' => (int) $data['allocated'] > 0 ? now() : null,
        ]);
        if ($quota->allocated > 0) {
            $quota->movements()->create([
                'type' => 'allocate',
                'qty' => $quota->allocated,
                'user_id' => auth()->id(),
            ]);
        }

        return $quota;
    }
}
