<?php

namespace App\Http\Requests;

use App\Models\LeadRequest;
use Illuminate\Validation\Rule;

/**
 * Заявка на контрактное производство.
 *
 * Поля свои, отличные от оптовой заявки: категория продукта и планируемый
 * объём вместо города и формата продаж (docs/07-design-review.md § 8.2).
 */
class StoreContractLeadRequest extends StoreLeadRequest
{
    protected function ownRules(): array
    {
        return [
            'product_category' => ['nullable', Rule::in(array_keys(LeadRequest::CONTRACT_CATEGORIES))],
            'planned_volume'   => ['nullable', 'string', 'max:120'],
            'comment'          => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function ownAttributes(): array
    {
        return [
            'product_category' => 'категория продукта',
            'planned_volume'   => 'планируемый объём',
            'comment'          => 'описание задачи',
        ];
    }
}
