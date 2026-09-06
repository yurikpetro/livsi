<?php

namespace App\Http\Requests;

use App\Models\LeadRequest;
use Illuminate\Validation\Rule;

/**
 * Заявка на оптовое партнёрство.
 *
 * Город и формат продаж вместо категории и объёма: менеджеру нужно понять,
 * где и как партнёр торгует, чтобы подобрать прайс.
 */
class StoreWholesaleLeadRequest extends StoreLeadRequest
{
    protected function ownRules(): array
    {
        return [
            'city'         => ['nullable', 'string', 'max:120'],
            'sales_format' => ['nullable', Rule::in(array_keys(LeadRequest::SALES_FORMATS))],
            'comment'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function ownAttributes(): array
    {
        return [
            'city'         => 'город',
            'sales_format' => 'формат продаж',
            'comment'      => 'комментарий',
        ];
    }
}
