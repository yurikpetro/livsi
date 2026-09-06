<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Общее у заявок обоих типов: контакты, согласие и приманка для ботов.
 *
 * Свои поля добавляет наследник. Разводить эти правила по двум классам
 * нельзя: правило согласия и антиспам должны меняться в одном месте,
 * иначе одна из форм однажды окажется слабее другой.
 */
abstract class StoreLeadRequest extends FormRequest
{
    /** Правила, специфичные для типа заявки. */
    abstract protected function ownRules(): array;

    /** Подписи полей, специфичных для типа заявки. */
    protected function ownAttributes(): array
    {
        return [];
    }

    public function rules(): array
    {
        return array_merge([
            'name'    => ['required', 'string', 'min:2', 'max:120'],
            'contact' => ['required', 'string', 'min:3', 'max:120'],
            'consent' => ['accepted'],

            // Приманка для ботов: поле скрыто от людей и должно остаться пустым.
            'company_website' => ['nullable', 'prohibited'],
        ], $this->ownRules());
    }

    public function attributes(): array
    {
        return array_merge([
            'name'    => 'имя',
            'contact' => 'способ связи',
        ], $this->ownAttributes());
    }

    public function messages(): array
    {
        return [
            'consent.accepted'           => 'Без согласия на обработку данных мы не можем принять заявку.',
            'company_website.prohibited' => 'Заявка не отправлена. Обновите страницу и попробуйте ещё раз.',
        ];
    }
}
