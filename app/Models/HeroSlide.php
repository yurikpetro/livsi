<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Баннер первого экрана главной — по одному на линейку (fresh, sweet, warm,
 * base, pro). Картинка для компьютера обязательна, для телефона — по желанию:
 * без неё телефон режет картинку для компьютера вокруг главной точки.
 */
class HeroSlide extends Model
{
    /** Рекомендуемые и минимальные размеры, по ним же предупреждения в админке. */
    public const DESKTOP = ['width' => 2560, 'height' => 1440, 'min_width' => 1920, 'min_height' => 1080, 'ratio' => '16:9'];

    public const MOBILE = ['width' => 1080, 'height' => 1920, 'min_width' => 900, 'min_height' => 1600, 'ratio' => '9:16'];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
        'desktop_focus_x' => 'integer',
        'desktop_focus_y' => 'integer',
        'mobile_focus_x' => 'integer',
        'mobile_focus_y' => 'integer',
    ];

    /** @param Builder<HeroSlide> $query */
    public function scopeShown(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('desktop_image')->orderBy('sort')->orderBy('id');
    }

    /** CSS object-position для компьютера: «72% 45%». */
    public function desktopFocus(): string
    {
        return self::position($this->desktop_focus_x, $this->desktop_focus_y);
    }

    /**
     * Для телефона: своя точка, если задана; иначе точка компьютерной картинки
     * (когда своей картинки для телефона нет, режем её вокруг той же точки).
     */
    public function mobileFocus(): string
    {
        if ($this->mobile_focus_x !== null && $this->mobile_focus_y !== null) {
            return self::position($this->mobile_focus_x, $this->mobile_focus_y);
        }

        return $this->mobile_image ? '50% 50%' : $this->desktopFocus();
    }

    private static function position(?int $x, ?int $y): string
    {
        return max(0, min(100, $x ?? 50)).'% '.max(0, min(100, $y ?? 50)).'%';
    }
}
