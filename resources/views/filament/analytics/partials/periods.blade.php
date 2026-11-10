{{-- Переключатель периода. $range — App\Analytics\Period. --}}
<div class="la-periods">
    @foreach (\App\Analytics\Period::OPTIONS as $key => $label)
        <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['is-on' => $range->key === $key])>{{ $label }}</button>
    @endforeach
    <small>{{ $range->range() }}</small>
    <span wire:loading class="la-muted" style="font-size: .8rem;">загружаю…</span>
</div>
