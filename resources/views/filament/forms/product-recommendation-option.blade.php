<span class="product-recommendation-option" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; min-width: 0;">
    <span style="min-width: 0; white-space: normal;">
        {{ $product->title }}
        @if (filled($product->sku))
            <small style="display: block; opacity: .65;">{{ $product->sku }}</small>
        @endif
    </span>
    @if ($image = $product->images->first())
        <img src="{{ asset($image->path) }}" alt="" width="40" height="40" loading="lazy"
             style="width: 40px; height: 40px; flex-shrink: 0; object-fit: cover; border-radius: 4px;">
    @else
        <span aria-hidden="true" style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0; opacity: .4;">—</span>
    @endif
</span>
