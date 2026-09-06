<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContractLeadRequest;
use App\Http\Requests\StoreWholesaleLeadRequest;
use App\Mail\ContractLeadReceived;
use App\Mail\WholesaleLeadReceived;
use App\Models\LeadRequest;
use App\Models\Setting;
use App\Support\Text;
use App\Support\Utm;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeadRequestController extends Controller
{
    /** Заявка на контрактное производство. */
    public function storeContract(StoreContractLeadRequest $request): RedirectResponse
    {
        $lead = $this->store($request, LeadRequest::TYPE_CONTRACT, [
            'product_category' => $request->input('product_category') ?: null,
            'planned_volume'   => $this->clean($request, 'planned_volume'),
        ]);

        $this->notifyManager($lead, new ContractLeadReceived($lead));

        return $this->done('contract');
    }

    /** Заявка на оптовое партнёрство. */
    public function storeWholesale(StoreWholesaleLeadRequest $request): RedirectResponse
    {
        $lead = $this->store($request, LeadRequest::TYPE_WHOLESALE, [
            'city'         => $this->clean($request, 'city'),
            'sales_format' => $request->input('sales_format') ?: null,
        ]);

        $this->notifyManager($lead, new WholesaleLeadReceived($lead));

        return $this->done('partners');
    }

    /**
     * Запись заявки. Общее у обоих типов — контакты, комментарий и след
     * согласия; различаются только собственные поля.
     */
    private function store(Request $request, string $type, array $own): LeadRequest
    {
        return LeadRequest::create(array_merge([
            'type'    => $type,
            'name'    => $this->clean($request, 'name'),
            'contact' => $this->clean($request, 'contact'),
            'comment' => $this->clean($request, 'comment'),

            // Согласие фиксируем временем, а не галочкой: галочка ничего
            // не доказывает, а 152-ФЗ требует подтверждаемого факта.
            'consent_at' => now(),
            'ip'         => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'utm'        => Utm::current($request),
            'status'     => LeadRequest::STATUS_NEW,
        ], $own));
    }

    private function clean(Request $request, string $field): ?string
    {
        return Text::utf8($request->string($field)->trim()->value()) ?: null;
    }

    private function done(string $route): RedirectResponse
    {
        return redirect()
            ->route($route, ['sent' => 1])
            ->with('lead_sent', true);
    }

    /**
     * Уведомление менеджеру. Адрес берётся из настроек, чтобы заказчик мог
     * поменять его сам на экране «Настройки сайта».
     */
    private function notifyManager(LeadRequest $lead, Mailable $letter): void
    {
        $to = Setting::get('manager_email') ?: config('livsi.manager_email');

        if (! $to) {
            Log::warning('Заявка сохранена, но уведомление не отправлено: не задан адрес менеджера.', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        // Заявка уже в базе, поэтому падение почты не должно ломать ответ:
        // человек увидел бы ошибку и отправил всё второй раз.
        try {
            Mail::to($to)->queue($letter);
        } catch (\Throwable $e) {
            Log::error('Не удалось поставить уведомление о заявке в очередь.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
