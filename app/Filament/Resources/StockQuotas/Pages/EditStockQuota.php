<?php

namespace App\Filament\Resources\StockQuotas\Pages;

use App\Filament\Resources\StockQuotas\StockQuotaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditStockQuota extends EditRecord
{
    protected static string $resource = StockQuotaResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
        $allocated = (int) $data['allocated'];
        if ($allocated < $record->reserved + $record->sold) {
            throw ValidationException::withMessages(['data.allocated' => 'Выделенное количество не может быть меньше резерва и уже проданного.']);
        }
        $difference = $allocated - $record->allocated;
        $record->fill(['allocated' => $allocated, 'low_threshold' => (int) $data['low_threshold']]);
        if ($difference !== 0) {
            $record->allocated_at = now();
        }
        $record->save();
        if ($difference !== 0) {
            $record->movements()->create([
                'type' => 'adjust',
                'qty' => $difference,
                'user_id' => auth()->id(),
            ]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
