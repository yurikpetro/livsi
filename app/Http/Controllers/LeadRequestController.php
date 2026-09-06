<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContractLeadRequest;
use App\Mail\ContractLeadReceived;
use App\Models\LeadRequest;
use App\Models\Setting;
use App\Support\Text;
use App\Support\Utm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeadRequestController extends Controller
{
    public function storeContract(StoreContractLeadRequest $request): RedirectResponse
    {
        $lead = LeadRequest::create([
            'type'             => LeadRequest::TYPE_CONTRACT,
            'name'             => Text::utf8($request->string('name')->trim()->value()),
            'contact'          => Text::utf8($request->string('contact')->trim()->value()),
            'product_category' => $request->input('product_category') ?: null,
            'planned_volume'   => Text::utf8($request->string('planned_volume')->trim()->value()) ?: null,
            'comment'          => Text::utf8($request->string('comment')->trim()->value()) ?: null,

            // Согласие фиксируем временем, а не галочкой: галочка ничего
            // не доказывает, а 152-ФЗ требует подтверждаемого факта.
            'consent_at'       => now(),
            'ip'               => $request->ip(),
            'user_agent'       => mb_substr((string) $request->userAgent(), 0, 255),
            'utm'              => Utm::current($request),
            'status'           => LeadRequest::STATUS_NEW,
        ]);

        $this->notifyManager($lead);

        return redirect()
            ->route('contract', ['sent' => 1])
            ->with('lead_sent', true);
    }

    /**
     * Уведомление менеджеру. Адрес берётся из настроек, чтобы заказчик мог
     * поменять его без разработчика; на время, пока экрана настроек нет,
     * работает значение из окружения.
     */
    private function notifyManager(LeadRequest $lead): void
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
            Mail::to($to)->queue(new ContractLeadReceived($lead));
        } catch (\Throwable $e) {
            Log::error('Не удалось поставить уведомление о заявке в очередь.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
