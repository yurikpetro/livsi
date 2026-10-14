<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UgcItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'media_paths' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<int, array{path: string, type: string}> */
    public function galleryMedia(): array
    {
        $media = [];
        foreach ($this->media_paths ?? [] as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['mp4', 'webm', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                $media[] = ['path' => $path, 'type' => in_array($extension, ['mp4', 'webm'], true) ? 'video' : 'image'];
            }
        }

        if ($media) {
            return $media;
        }

        if ($this->video_path) {
            return [
                ['path' => $this->video_path, 'type' => 'video'],
                ['path' => $this->image_path, 'type' => 'image'],
            ];
        }

        return [['path' => $this->image_path, 'type' => 'image']];
    }
}
