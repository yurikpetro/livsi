<?php

namespace App\Filament\Resources\HeroSlides\Pages;

use App\Filament\Resources\HeroSlides\HeroSlideResource;
use App\Support\ImageDerivatives;
use Filament\Resources\Pages\EditRecord;

class EditHeroSlide extends EditRecord
{
    protected static string $resource = HeroSlideResource::class;

    public function getTitle(): string
    {
        return 'Баннер '.mb_strtoupper((string) $this->getRecord()->code);
    }

    /**
     * Пустое поле картинки для компьютера — значит, оставить прежнюю: исходник
     * мог лежать на сервере только в виде лёгких копий, и поле его не видит.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['desktop_image'] = ($data['desktop_image'] ?? null) ?: $this->getRecord()->desktop_image;

        return $data;
    }

    /** Новая картинка — сразу лёгкие копии для быстрой загрузки сайта. */
    protected function afterSave(): void
    {
        $slide = $this->getRecord();
        foreach (['desktop_image', 'mobile_image'] as $field) {
            if ($slide->wasChanged($field) && filled($slide->{$field})) {
                ImageDerivatives::make($slide->{$field});
            }
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
