<?php

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\EditRecord;

class EditReview extends EditRecord
{
    protected static string $resource = ReviewResource::class;

    /** Удаления нет: карточка — часть композиции блока, а не отдельная запись. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
