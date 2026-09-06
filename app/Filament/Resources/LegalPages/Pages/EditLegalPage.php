<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use Filament\Resources\Pages\EditRecord;

class EditLegalPage extends EditRecord
{
    protected static string $resource = LegalPageResource::class;

    /**
     * Удалять документы нельзя: маршрут останется объявленным
     * и адрес начнёт отдавать 404, а на него ссылаются чеки и письма.
     * Чтобы убрать документ с сайта, есть переключатель «Показывать».
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
