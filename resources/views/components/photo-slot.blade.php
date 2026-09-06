@props([
    'path',
    'alt' => '',
    'number' => null,
    'caption' => null,
    'sizes' => '100vw',
    'priority' => false,
    'placeholder' => 'Фото готовится',
])

@php
    use App\Support\Media;

    $hasPhoto = Media::available($path);
@endphp

{{--
    Кадр с подписью — как на страницах заявок в прототипе: фиксированная
    высота, а внизу белая полоса во всю ширину с номером в зелёном квадрате.

    Пока файла нет, вместо фотографии показывается явная заглушка с подписью,
    а не пустое место: пустой блок читается как поломанная вёрстка.
--}}
<figure {{ $attributes->class(['relative h-[380px] overflow-hidden bg-shell md:h-[560px]']) }}>
    @if ($hasPhoto)
        <x-img :path="$path" :alt="$alt" :sizes="$sizes"
               :loading="$priority ? 'eager' : 'lazy'"
               :fetchpriority="$priority ? 'high' : null"
               class="h-full w-full object-cover" />
    @else
        <div class="flex h-full items-center justify-center border border-dashed border-line p-8">
            <span class="text-center text-[10px] font-bold uppercase tracking-[0.12em] text-muted">
                {{ $placeholder }}
            </span>
        </div>
    @endif

    @if ($caption)
        <figcaption class="absolute inset-x-0 bottom-0 flex min-h-[54px] items-center gap-[18px] bg-paper px-4 md:min-h-[58px] md:px-[18px]">
            @if ($number)
                <span class="grid size-[30px] flex-none place-items-center bg-neon text-[9px] font-black">
                    {{ $number }}
                </span>
            @endif
            <b class="text-[10px] font-bold uppercase leading-tight tracking-[0.025em] md:text-[11px]">
                {{ $caption }}
            </b>
        </figcaption>
    @endif
</figure>
