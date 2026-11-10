@php
    $at = isset($autopilot['last_run_at']) ? \Illuminate\Support\Carbon::parse($autopilot['last_run_at'])->timezone(\App\Analytics\Period::TZ) : null;
    $health = (array) ($autopilot['health'] ?? []);
    $closed = $health !== [] && ! ($health['ok'] ?? true);
@endphp
@if ($closed)
    <div class="la-callout" style="box-shadow: inset 0 0 0 1px rgba(220, 38, 38, .6);">
        <b style="color: #dc2626;">Поисковики не могут нормально видеть сайт.</b> Пока это не исправлено, Яндекс убирает страницы из выдачи.
        <ul style="margin: 6px 0 0 18px; list-style: disc;">
            @foreach ((array) ($health['problems'] ?? []) as $problem)
                <li>{{ $problem }}</li>
            @endforeach
        </ul>
    </div>
@endif
<x-filament::section heading="SEO-автопилот" collapsible :collapsed="! $closed && $at !== null"
    description="Каждую ночь проверяет, что сайт открыт для поисковиков, держит в порядке карту сайта в Вебмастере и отправляет на переобход главную, новые страницы и старые адреса из выдачи. О серьёзных проблемах пишет в Telegram, по понедельникам — сводка.">
    @if (! $at)
        <p class="la-note">Ещё не запускался. Первый запуск — этой ночью, или нажмите «Запустить автопилот» вверху.</p>
    @else
        <p class="la-note" style="margin-bottom: 6px;">Последний запуск: <b>{{ $at->format('d.m.Y H:i') }}</b> (МСК).</p>
        <ul style="margin: 0 0 0 18px; list-style: disc; font-size: .875rem; line-height: 1.5;">
            @foreach ((array) ($autopilot['log'] ?? []) as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
