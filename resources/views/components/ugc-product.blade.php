@props(['item'])

@if ($item->product?->is_active)
    <a href="{{ route('catalog.show', $item->product) }}" x-data x-on:click.prevent="$el.closest('dialog')?.close(); Livewire.dispatch('quick-view', { productId: {{ $item->product->id }} })" class="ugc-product">
        @if ($item->product->primaryImage())<img src="{{ asset($item->product->primaryImage()->path) }}" alt="" loading="lazy">@endif
        <span><b>{{ $item->product->title }}</b><small>{{ \App\Support\Money::rub($item->product->priceFrom()) }}</small></span><span aria-hidden="true">↗</span>
    </a>
@endif
