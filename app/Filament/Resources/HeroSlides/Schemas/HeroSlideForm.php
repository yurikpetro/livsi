<?php

namespace App\Filament\Resources\HeroSlides\Schemas;

use App\Models\HeroSlide;
use App\Support\Media;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Редактор баннера: слева тексты и картинки, справа предпросмотр на экране
 * компьютера и телефона. Нажатие на предпросмотр ставит главную точку —
 * вокруг неё экран режет картинку.
 */
class HeroSlideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(['default' => 1, 'lg' => 5])->schema([
                Group::make([
                    Section::make('Картинки')
                        ->description('Компьютер: 2560×1440 (16:9), не меньше 1920×1080. Телефон: 1080×1920 (9:16), не меньше 900×1600. JPG, PNG или WebP до 10 МБ — лёгкие копии сайт сделает сам.')
                        ->schema([
                            self::upload('desktop_image', 'Картинка для компьютера', ['16:9', '2:1', null])
                                ->helperText('Чтобы заменить — нажмите × и загрузите новую. Если оставить поле пустым, останется прежняя картинка.'),
                            self::upload('mobile_image', 'Картинка для телефона', ['9:16', '3:4', null])
                                ->helperText('Необязательно. Без неё телефон покажет картинку для компьютера, обрезанную вокруг главной точки.'),
                        ]),
                    Section::make('Тексты')->schema([
                        TextInput::make('title_first')->label('Заголовок, первая строка')->required()->maxLength(60)->live(onBlur: true),
                        TextInput::make('title_second')->label('Вторая строка')->maxLength(60)->live(onBlur: true),
                        TextInput::make('title_accent')->label('Третья строка (цветом линейки)')->maxLength(60)->live(onBlur: true),
                        Textarea::make('description')->label('Описание')->rows(3)->maxLength(400)->live(onBlur: true),
                        TextInput::make('aroma')->label('Характер аромата')->maxLength(120)->live(onBlur: true),
                        Grid::make(2)->schema([
                            ColorPicker::make('accent_color')->label('Цвет линейки')->required()
                                ->regex('/^#[0-9a-fA-F]{6}$/')->live(),
                            TextInput::make('tab_label')->label('Подпись на вкладке')->maxLength(40),
                        ]),
                        Toggle::make('is_active')->label('Показывать на главной'),
                    ]),
                ])->columnSpan(['lg' => 2]),
                Group::make([
                    Section::make('Как будет на сайте')
                        ->description('Нажмите на картинку там, где лицо или банка, — это главная точка: её не обрежет ни один экран.')
                        ->schema([
                            ViewField::make('preview')->hiddenLabel()->dehydrated(false)
                                ->view('filament.forms.hero-slide-preview'),
                            Hidden::make('desktop_focus_x'),
                            Hidden::make('desktop_focus_y'),
                            Hidden::make('mobile_focus_x'),
                            Hidden::make('mobile_focus_y'),
                        ]),
                ])->columnSpan(['lg' => 3]),
            ]),
        ]);
    }

    /** @param list<string|null> $ratios */
    private static function upload(string $field, string $label, array $ratios): FileUpload
    {
        return FileUpload::make($field)
            ->label($label)
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->disk('public_images')
            ->directory('img/banners')
            ->visibility('public')
            ->maxSize(10240)
            ->imageEditor()
            ->imageEditorAspectRatioOptions($ratios)
            ->saveUploadedFileUsing(self::store(...))
            ->live();
    }

    private static function store(FileUpload $component, TemporaryUploadedFile $file): string
    {
        $path = $component->saveUploadedFile($file);
        if (! $path || ! $component->getDisk()->exists($path)) {
            throw ValidationException::withMessages([
                $component->getStatePath() => 'Не удалось сохранить картинку. Повторите загрузку; если ошибка остаётся, проверьте доступ к папке public/img/banners.',
            ]);
        }

        return $path;
    }

    /**
     * Оценка картинки для подсказки под предпросмотром.
     *
     * @param  array{width: int, height: int, min_width: int, min_height: int, ratio: string}  $spec
     * @return array{tone: string, text: string}|null
     */
    public static function assess(?string $file, array $spec, bool $portrait, ?array $dimensions = null): ?array
    {
        if ($file && is_file($file) && ($size = @getimagesize($file))) {
            [$width, $height] = $size;
            $bytes = (int) filesize($file);
        } elseif ($dimensions) {
            [$width, $height, $bytes] = [(int) $dimensions['width'], (int) $dimensions['height'], 0];
        } else {
            return null;
        }
        $weight = $bytes >= 1048576 ? ', '.number_format($bytes / 1048576, 1, ',', '').' МБ' : ($bytes > 0 ? ', '.max(1, (int) round($bytes / 1024)).' КБ' : '');
        [$a, $b] = array_map('intval', explode(':', $spec['ratio']));
        $off = abs(($width / $height) / ($a / $b) - 1);

        return match (true) {
            $portrait && $width > $height => ['tone' => 'bad', 'text' => "Картинка горизонтальная ({$width}×{$height}), а для телефона нужна вертикальная — лучше {$spec['width']}×{$spec['height']}."],
            ! $portrait && $height > $width => ['tone' => 'bad', 'text' => "Картинка вертикальная ({$width}×{$height}), а для компьютера нужна горизонтальная — лучше {$spec['width']}×{$spec['height']}."],
            $width < $spec['min_width'] || $height < $spec['min_height'] => ['tone' => 'warn', 'text' => "Маловато: {$width}×{$height}. На больших экранах будет мыльно — нужно от {$spec['min_width']}×{$spec['min_height']}, лучше {$spec['width']}×{$spec['height']}."],
            $off > 0.15 => ['tone' => 'warn', 'text' => "Размер хороший ({$width}×{$height}), но пропорции не {$spec['ratio']} — края обрежутся сильнее. Проверьте кадр выше."],
            default => ['tone' => 'ok', 'text' => "Хорошо: {$width}×{$height}{$weight}."],
        };
    }

    /**
     * Адрес для предпросмотра, путь к файлу и размеры. Пустое поле — значит,
     * остаётся сохранённая картинка (её исходник может лежать только в виде
     * лёгких копий — тогда показываем копию).
     *
     * @return array{0: ?string, 1: ?string, 2: ?array{width: int, height: int}}
     */
    public static function file(mixed $state, ?string $saved = null): array
    {
        $value = is_array($state) ? collect($state)->first() : $state;
        if ($value instanceof TemporaryUploadedFile) {
            try {
                return [$value->temporaryUrl(), $value->getRealPath(), null];
            } catch (\Throwable) {
                return [null, $value->getRealPath(), null];
            }
        }
        $path = is_string($value) && $value !== '' ? $value : $saved;
        if (! $path) {
            return [null, null, null];
        }
        if (is_file(public_path($path))) {
            return [asset($path), public_path($path), null];
        }

        return Media::has($path) ? [Media::fallback($path), null, Media::dimensions($path)] : [null, null, null];
    }

    /** Подсказки по размерам для шаблона предпросмотра. */
    public static function specs(): array
    {
        return ['desktop' => HeroSlide::DESKTOP, 'mobile' => HeroSlide::MOBILE];
    }
}
