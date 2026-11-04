@php
    use App\Filament\Resources\HeroSlides\Schemas\HeroSlideForm;
    use App\Support\ImageDerivatives;
    use Illuminate\Support\Str;

    $base = Str::beforeLast($getStatePath(), '.');
    [$desktopUrl, $desktopFile, $desktopSize] = HeroSlideForm::file($get('desktop_image'), $record?->desktop_image);
    [$mobileUrl, $mobileFile, $mobileSize] = HeroSlideForm::file($get('mobile_image'));
    $specs = HeroSlideForm::specs();
    $notes = array_values(array_filter([
        HeroSlideForm::assess($desktopFile, $specs['desktop'], false, $desktopSize),
        $mobileUrl
            ? HeroSlideForm::assess($mobileFile, $specs['mobile'], true, $mobileSize)
            : ['tone' => 'warn', 'text' => 'Своей картинки для телефона нет — телефон покажет картинку для компьютера, обрезанную вокруг точки. Лучше загрузить вертикальную '.$specs['mobile']['width'].'×'.$specs['mobile']['height'].'.'],
        ImageDerivatives::available() ? null
            : ['tone' => 'warn', 'text' => 'На сервере нет обработки картинок (GD): лёгкие копии не сделаются, поэтому загружайте JPG до 1 МБ.'],
    ]));
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $get('accent_color')) ? $get('accent_color') : '#d0e64f';
    [$first, $second, $third] = [(string) $get('title_first'), (string) $get('title_second'), (string) $get('title_accent')];
    $tones = ['ok' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c'];
    $frame = 'position:relative;overflow:hidden;border-radius:8px;background:#e7e5df;cursor:crosshair;';
    $fill = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;pointer-events:none;';
    $shade = 'position:absolute;inset:0;background:linear-gradient(90deg,#000b,#0007 65%,#0008);pointer-events:none;';
    $dot = 'position:absolute;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;border:3px solid #fff;background:#2563eb;box-shadow:0 0 0 1px #0007;pointer-events:none;';
    $empty = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:12px;text-align:center;font-size:13px;color:#6b6b66;';
@endphp

<div data-hero-preview data-has-mobile="{{ $mobileUrl ? '1' : '0' }}"
     x-data="{
        dx: $wire.entangle('{{ $base }}.desktop_focus_x'),
        dy: $wire.entangle('{{ $base }}.desktop_focus_y'),
        mx: $wire.entangle('{{ $base }}.mobile_focus_x'),
        my: $wire.entangle('{{ $base }}.mobile_focus_y'),
        at(event) {
            const box = event.currentTarget.getBoundingClientRect();
            const clamp = (value) => Math.round(Math.min(100, Math.max(0, value)));
            return [clamp((event.clientX - box.left) / box.width * 100), clamp((event.clientY - box.top) / box.height * 100)];
        },
        get desktop() { return [this.dx ?? 50, this.dy ?? 47]; },
        get ownMobile() { return this.mx !== null && this.mx !== undefined && this.my !== null && this.my !== undefined; },
        get mobile() {
            if (this.ownMobile) return [this.mx, this.my];
            return this.$root.dataset.hasMobile === '1' ? [50, 50] : this.desktop;
        },
     }"
     style="display:grid;gap:18px">

    <div>
        <div style="font-size:13px;color:#6b6b66;margin-bottom:6px">Компьютер · экран 1440 px</div>
        <div data-preview-desktop x-on:click="[dx, dy] = at($event)" style="{{ $frame }}aspect-ratio:1440/780">
            @if ($desktopUrl)
                <img src="{{ $desktopUrl }}" alt="" x-bind:style="{ objectPosition: desktop[0] + '% ' + desktop[1] + '%' }" style="{{ $fill }}">
                <div style="{{ $shade }}"></div>
            @else
                <div style="{{ $empty }}">Загрузите картинку для компьютера</div>
            @endif
            <div style="position:absolute;left:6%;top:50%;transform:translateY(-50%);color:#fff;font-weight:800;line-height:1;letter-spacing:-.02em;font-size:clamp(14px,2.4vw,30px);pointer-events:none;text-transform:uppercase">
                <div>{{ $first }}</div><div style="padding-left:.5em">{{ $second }}</div><div style="color:{{ $accent }}">{{ $third }}</div>
            </div>
            <span x-bind:style="{ left: desktop[0] + '%', top: desktop[1] + '%' }" style="{{ $dot }}"></span>
        </div>
        <div style="font-size:12px;color:#6b6b66;margin-top:6px">На мониторах шире 1440 px картинка срезается сильнее сверху и снизу, на узких ноутбуках — по бокам. Слева текст: главное лучше держать справа.</div>
    </div>

    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
        <div>
            <div style="font-size:13px;color:#6b6b66;margin-bottom:6px">Телефон · 390 px</div>
            <div data-preview-mobile x-on:click="[mx, my] = at($event)" style="{{ $frame }}width:170px;aspect-ratio:390/790;border:5px solid #1f1f1f;border-radius:22px">
                @if ($mobileUrl ?? $desktopUrl)
                    <img src="{{ $mobileUrl ?? $desktopUrl }}" alt="" x-bind:style="{ objectPosition: mobile[0] + '% ' + mobile[1] + '%' }" style="{{ $fill }}">
                    <div style="{{ $shade }}"></div>
                @else
                    <div style="{{ $empty }}">Нет картинки</div>
                @endif
                <div style="position:absolute;left:9%;right:9%;bottom:19%;color:#fff;font-weight:800;line-height:1.04;font-size:16px;pointer-events:none;text-transform:uppercase">
                    <div>{{ $first }}</div><div style="padding-left:.5em">{{ $second }}</div><div style="color:{{ $accent }}">{{ $third }}</div>
                </div>
                <div style="position:absolute;left:9%;right:9%;bottom:13%;height:4px;background:#ffffff99;pointer-events:none"></div>
                <div style="position:absolute;left:0;right:0;bottom:12px;height:22px;margin:0 8px;background:#fff;pointer-events:none"></div>
                <span x-bind:style="{ left: mobile[0] + '%', top: mobile[1] + '%' }" style="{{ $dot }}"></span>
            </div>
        </div>
        <div style="flex:1;min-width:220px;display:grid;gap:10px;font-size:13px;line-height:1.45">
            <div style="color:#6b6b66">Нажмите на телефон, чтобы поставить для него свою главную точку.
                <span x-show="ownMobile"><button type="button" x-on:click="mx = null; my = null" style="color:#2563eb;text-decoration:underline">Как на компьютере</button></span>
            </div>
            @foreach ($notes as $note)
                <div data-preview-note="{{ $note['tone'] }}" style="padding:8px 10px;border-left:3px solid {{ $tones[$note['tone']] }};background:#f6f5f1;color:{{ $tones[$note['tone']] }}">{{ $note['text'] }}</div>
            @endforeach
            <div style="color:#6b6b66">На телефоне нижнюю треть занимают текст и вкладки линеек: лицо и товар лучше держать в верхней половине кадра.</div>
        </div>
    </div>
</div>
