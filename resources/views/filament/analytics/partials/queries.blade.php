{{-- Таблица запросов Вебмастера. $rows — Webmaster::popular()['rows'], $brand — регулярка бренда. --}}
@php
    $n = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $f = fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) $v);
@endphp
<div class="la-scroll">
    <table class="la-table">
        <thead><tr><th>Запрос</th><th class="num">Показы</th><th class="num">Клики</th><th class="num">CTR</th><th class="num">Позиция</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr @class(['is-brand' => preg_match($brand, $row['query'])])>
                    <td>{{ $row['query'] }}</td>
                    <td class="num">{{ $n($row['shows']) }}</td>
                    <td class="num">{{ $n($row['clicks']) }}</td>
                    <td class="num">{{ $f($row['ctr']) }}%</td>
                    <td class="num">{{ $f($row['position']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
