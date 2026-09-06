<?php

namespace App\Http\Requests;

use App\Models\LeadRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Заявка на контрактное производство.
 *
 * Поля свои, отличные от оптовой заявки: категория продукта и планируемый
 * объём вместо города и формата продаж (docs/07-design-review.md § 8.2).
 */
class StoreContractLeadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'min:2', 'max:120'],
            'contact'          => ['required', 'string', 'min:3', 'max:120'],
            'product_category' => ['nullable', Rule::in(array_keys(LeadRequest::CONTRACT_CATEGORIES))],
            'planned_volume'   => ['nullable', 'string', 'max:120'],
            'comment'          => ['nullable', 'string', 'max:2000'],
            'consent'          => ['accepted'],

            // Приманка для ботов: поле скрыто от людей и должно остаться пустым.
            'company_website'  => ['nullable', 'prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'             => 'имя',
            'contact'          => 'способ связи',
            'product_category' => 'категория продукта',
            'planned_volume'   => 'планируемый объём',
            'comment'          => 'описание задачи',
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted'          => 'Без согласия на обработку данных мы не можем принять заявку.',
            'company_website.prohibited' => 'Заявка не отправлена. Обновите страницу и попробуйте ещё раз.',
        ];
    }
}
