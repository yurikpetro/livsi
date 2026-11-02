@if ($products->isNotEmpty())
    <x-filament::section heading="Товары без вариантов" description="Чтобы указать остаток, добавьте вариант с артикулом в карточке товара. После сохранения он сразу появится в таблице выше.">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($products as $product)
                <li class="flex items-center gap-4 py-3">
                    @if ($image = $product->images->first())
                        <img src="{{ asset($image->path) }}" alt="" width="40" height="40" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" loading="lazy">
                    @endif
                    <div class="flex-1">
                        <div>{{ $product->title }}</div>
                        <div class="text-sm text-gray-500">{{ $product->sku }} · {{ $product->is_active ? 'Активен' : 'Неактивен' }}</div>
                    </div>
                    <x-filament::link :href="\App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product])">Добавить вариант</x-filament::link>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
@endif
