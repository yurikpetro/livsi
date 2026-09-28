<?php

namespace App\Monitoring;

use App\Filament\Resources\LeadRequests\LeadRequestResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\StockQuotas\StockQuotaResource;
use App\Models\LeadRequest;
use App\Models\OrderIssue;
use App\Models\StockQuota;
use App\Models\User;
use RuntimeException;

class BusinessReport
{
    public function render(string $kind, int $recordId, ?string $detail = null): ?string
    {
        return match ($kind) {
            'lead' => $this->lead($recordId),
            'stock_low', 'stock_empty' => $this->stock($recordId, $kind),
            'user' => $this->user($recordId, $detail),
            'order_issue' => $this->issue($recordId),
            default => throw new RuntimeException("Неизвестное событие Telegram: {$kind}."),
        };
    }

    private function lead(int $id): ?string
    {
        $lead = LeadRequest::find($id);
        if (! $lead) {
            return null;
        }

        return $this->lines([
            '📩 Новая заявка: '.$lead->typeLabel(),
            'Номер: '.$lead->id,
            $lead->type === LeadRequest::TYPE_CONTRACT
                ? 'Категория: '.(LeadRequest::CONTRACT_CATEGORIES[$lead->product_category] ?? 'не указана')
                : 'Город: '.($lead->city ?: 'не указан'),
        ], LeadRequestResource::getUrl('edit', ['record' => $lead], panel: 'admin'));
    }

    private function stock(int $id, string $kind): ?string
    {
        $quota = StockQuota::with('variant.product')->find($id);
        if (! $quota) {
            return null;
        }

        $available = $quota->available();
        if ($available > $quota->low_threshold || ($kind === 'stock_low' && $available === 0) || ($kind === 'stock_empty' && $available > 0)) {
            return null;
        }

        return $this->lines([
            $kind === 'stock_empty' ? '🔴 Товар закончился' : '🟡 Остаток заканчивается',
            'Товар: '.($quota->variant?->product?->title ?: '—'),
            'Вариант: '.($quota->variant?->optionLabel() ?: '—'),
            'Артикул: '.($quota->variant?->sku ?: '—'),
            'Доступно: '.$available.' шт. (порог '.$quota->low_threshold.')',
        ], StockQuotaResource::getUrl('edit', ['record' => $quota], panel: 'admin'));
    }

    private function user(int $id, ?string $provider): ?string
    {
        $user = User::find($id);
        if (! $user || $user->is_admin) {
            return null;
        }

        return $this->lines([
            '👤 Новый аккаунт покупателя',
            'ID: '.$user->id,
            'Способ: '.($provider ?: 'не указан'),
        ]);
    }

    private function issue(int $id): ?string
    {
        $issue = OrderIssue::with('order')->find($id);
        if (! $issue || ! $issue->order) {
            return null;
        }

        return $this->lines([
            $issue->kind === OrderIssue::REFUND_REQUEST ? '↩️ Запрос на возврат' : '🚚 Проблема доставки',
            'Заказ: '.$issue->order->number,
            'Обращение: '.$issue->id,
            'Статус: требует проверки менеджером',
        ], OrderResource::getUrl('edit', ['record' => $issue->order], panel: 'admin'));
    }

    private function lines(array $lines, ?string $url = null): string
    {
        $html = array_map([ErrorReport::class, 'escape'], $lines);
        $html[0] = '<b>'.$html[0].'</b>';

        if ($url) {
            $html[] = '';
            $html[] = '<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Открыть в админке</a>';
        }

        return implode("\n", $html);
    }
}
