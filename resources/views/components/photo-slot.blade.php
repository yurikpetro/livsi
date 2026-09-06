@props([
    'path',
    'alt' => '',
    'badge' => null,
    'badgeClass' => 'bg-neon',
    'tint' => 'bg-sand',
    'sizes' => '100vw',
    'priority' => false,
    'placeholder' => 'Фото готовится',
])

@php
    use App\Support\Media;

    $hasPhoto = Media::available($path);
@endphp

{{--
    Слот под фотографию. Пока файла нет, показывает явную заглушку с подписью,
    а не пустое место: пустой блок выглядит поломанной вёрсткой, а заглушка
    честно говорит, что кадр ещё не прислали.
--}}
<figure {{ $attributes->class(['relative overflow-hidden', $tint => ! $hasPhoto]) }}>
    @if ($hasPhoto)
        <x-img :path="$path" :alt="$alt" :sizes="$sizes"
               :loading="$priority ? 'eager' : 'lazy'"
               :fetchpriority="$priority ? 'high' : null"
               class="h-full w-full object-cover" />
    @else
        <div class="flex h-full min-h-64 items-center justify-center border border-dashed border-line p-8">
            <span class="text-center text-[10px] font-bold uppercase tracking-[0.12em] text-muted">
                {{ $placeholder }}
            </span>
        </div>
    @endif

    @if ($badge)
        <figcaption class="absolute bottom-4 left-4 {{ $badgeClass }} px-3 py-2 text-[10px] font-bold uppercase tracking-[0.1em]">
            {{ $badge }}
        </figcaption>
    @endif
</figure>
