{{--
    Столбики по дням. $points — список ['date' => 'Y-m-d', 'a' => ?int, 'b' => ?int]:
    высота столбика — a, b только в подсказке. $labels — подписи ['a' => '…', 'b' => '…'].
    null — данных за этот день у Яндекса ещё нет (пунктир).
--}}
@php
    $max = max(1, ...array_map(fn ($p) => (int) ($p['a'] ?? 0), $points ?: [['a' => 0]]));
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m');
    $count = count($points);
@endphp
<div class="la-chart">
    @foreach ($points as $p)
        @php
            $a = $p['a'] ?? null; $b = $p['b'] ?? null;
            $title = $fmt($p['date']).': '.($a === null ? 'данных ещё нет' : $labels['a'].' '.number_format($a, 0, ',', ' '))
                .(isset($labels['b']) && $b !== null ? ', '.$labels['b'].' '.number_format($b, 0, ',', ' ') : '');
        @endphp
        <div title="{{ $title }}">
            @if ($a === null)
                <i class="is-empty" style="height: 1px;"></i>
            @else
                <i style="height: {{ round(100 * $a / $max, 2) }}%;"></i>
            @endif
        </div>
    @endforeach
</div>
@if ($count > 0)
    <div class="la-axis">
        <span>{{ $fmt($points[0]['date']) }}</span>
        @if ($count > 2)<span>{{ $fmt($points[intdiv($count, 2)]['date']) }}</span>@endif
        @if ($count > 1)<span>{{ $fmt($points[$count - 1]['date']) }}</span>@endif
    </div>
@endif
