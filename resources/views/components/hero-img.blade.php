@props([
    'slide',
    'loading' => 'lazy',
    'fetchpriority' => null,
])

{{-- Баннер главной: своя картинка для телефона (если загружена в админке) и
     картинка для компьютера. Обрезка вокруг главной точки — в CSS через
     --focus / --focus-m на .hero-photo. --}}
@php
    use App\Support\Media;

    $desktop = $slide->desktop_image;
    $mobile = $slide->mobile_image;
    $phone = '(max-width: 767px)';
    $dimensions = Media::dimensions($desktop);
@endphp

<picture>
    @if ($mobile)
        @foreach (Media::FORMATS as $format)
            @if ($srcset = Media::srcset($mobile, $format))
                <source media="{{ $phone }}" type="image/{{ $format }}" srcset="{{ $srcset }}" sizes="100vw">
            @endif
        @endforeach
        <source media="{{ $phone }}" srcset="{{ Media::srcset($mobile, 'jpg') ?? asset($mobile) }}" sizes="100vw">
    @endif
    @foreach (Media::FORMATS as $format)
        @if ($srcset = Media::srcset($desktop, $format))
            <source type="image/{{ $format }}" srcset="{{ $srcset }}" sizes="100vw">
        @endif
    @endforeach
    <img src="{{ Media::fallback($desktop) }}"
         @if ($jpg = Media::srcset($desktop, 'jpg')) srcset="{{ $jpg }}" sizes="100vw" @endif
         alt="{{ 'Линейка LIVSI '.mb_strtoupper($slide->code) }}"
         @if ($dimensions) width="{{ $dimensions['width'] }}" height="{{ $dimensions['height'] }}" @endif
         loading="{{ $loading }}"
         @if ($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
         decoding="async">
</picture>
