@props([
    'path',
    'alt' => '',
    'sizes' => '100vw',
    'width' => null,
    'height' => null,
    'loading' => 'lazy',
    'fetchpriority' => null,
])

@php
    use App\Support\Media;

    $dimensions = Media::dimensions($path);
    $width  ??= $dimensions['width'] ?? null;
    $height ??= $dimensions['height'] ?? null;
@endphp

@if (Media::has($path))
    {{-- Порядок источников важен: браузер берёт первый, который понимает,
         а AVIF весит меньше всех. --}}
    <picture>
        @foreach (Media::FORMATS as $format)
            @if ($srcset = Media::srcset($path, $format))
                <source type="image/{{ $format }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
            @endif
        @endforeach

        <img src="{{ Media::fallback($path) }}"
             @if ($jpg = Media::srcset($path, 'jpg')) srcset="{{ $jpg }}" sizes="{{ $sizes }}" @endif
             alt="{{ $alt }}"
             @if ($width) width="{{ $width }}" @endif
             @if ($height) height="{{ $height }}" @endif
             loading="{{ $loading }}"
             @if ($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
             decoding="async"
             {{ $attributes }}>
    </picture>
@else
    {{-- Производных нет: отдаём исходник. Так страница не ломается,
         если `npm run images` ещё не прогонялся. --}}
    <img src="{{ asset($path) }}"
         alt="{{ $alt }}"
         @if ($width) width="{{ $width }}" @endif
         @if ($height) height="{{ $height }}" @endif
         loading="{{ $loading }}"
         @if ($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
         decoding="async"
         {{ $attributes }}>
@endif
