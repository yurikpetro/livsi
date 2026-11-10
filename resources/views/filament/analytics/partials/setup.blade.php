{{-- Пока Яндекс не подключён: что даст подключение и куда нажать. --}}
<div class="la-callout">
    <b>{{ $title }}</b><br>
    @isset($text){{ $text }}<br>@endisset
    Откройте <a href="{{ \App\Filament\Pages\Analytics\AnalyticsConnection::getUrl() }}">«Аналитика сайта» → «Подключение»</a>
    и пройдите три шага — это один раз и около 10 минут. Ключи и коды вводятся только там.
</div>
