<?php

namespace App\Livewire;

use App\Models\Product;
use App\Services\CatalogQuery;
use App\Support\Text;
use Illuminate\Http\Request;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Поиск в шапке: подсказки по мере ввода, переход в каталог за полной выдачей.
 */
class SearchBox extends Component
{
    public bool $open = false;

    #[Url(except: '')]
    public string $q = '';

    /** Строка может прийти из адреса с битой кодировкой — чиним до сериализации. */
    public function mount(): void
    {
        $this->q = Text::utf8($this->q);
    }

    public function updatedQ(): void
    {
        $this->q = Text::utf8($this->q);
    }

    public function openSearch(): void
    {
        $this->open = true;
    }

    public function closeSearch(): void
    {
        $this->open = false;
    }

    public function render()
    {
        $suggestions = collect();

        if (mb_strlen(trim($this->q)) >= 2) {
            $query = CatalogQuery::fromRequest(new Request(['q' => $this->q]));

            $suggestions = $query->builder()->take(5)->get();
        }

        return view('livewire.search-box', [
            'suggestions' => $suggestions,
            'resultsUrl'  => route('catalog.index', ['q' => trim($this->q)]),
        ]);
    }
}