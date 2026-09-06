<?php

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    /** Кнопки создания нет: карточек в блоке ровно три, они заданы макетом. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return 'Блок отзывов на главной странице. Состав карточек задан макетом — меняется только наполнение. Средняя оценка над карточками правится в настройках сайта.';
    }
}
