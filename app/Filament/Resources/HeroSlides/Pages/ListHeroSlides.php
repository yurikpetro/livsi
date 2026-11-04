<?php

namespace App\Filament\Resources\HeroSlides\Pages;

use App\Filament\Resources\HeroSlides\HeroSlideResource;
use Filament\Resources\Pages\ListRecords;

class ListHeroSlides extends ListRecords
{
    protected static string $resource = HeroSlideResource::class;

    public function getSubheading(): ?string
    {
        return 'Первый экран главной: по баннеру на линейку. Порядок — перетаскиванием, скрыть — переключателем.';
    }
}
