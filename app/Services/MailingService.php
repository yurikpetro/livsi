<?php

namespace App\Services;

use App\Mail\MarketingMail;
use App\Models\Customer;
use App\Models\Mailing;
use App\Models\MailingRecipient;
use App\Models\PromoCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Рассылки клиентам. Письмо уходит только тем, кто дал согласие и не отписался;
 * остальные из выбранных пропускаются (их видно в итогах). Отправка — по
 * расписанию пачками (mailings:send), чтобы почтовый сервер не счёл нас спамом
 * и чтобы рассылка не зависела от очереди задач.
 */
class MailingService
{
    public const BATCH = 30;

    public function __construct(private readonly PromoCodeService $promoCodes) {}

    /**
     * @param  array{subject: string, body: string, promo?: ?array{kind: string, value?: int, days?: ?int, min_total?: ?int}}  $data
     * @param  iterable<int, Customer>|Collection<int, Customer>  $customers
     */
    public function create(array $data, iterable $customers, string $by): Mailing
    {
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if (mb_strlen($subject) < 3 || mb_strlen($body) < 10) {
            throw ValidationException::withMessages(['mailing' => 'Напишите тему (от 3 знаков) и текст письма (от 10 знаков).']);
        }
        $promo = $data['promo'] ?? null;
        $customers = collect($customers)->unique('id');
        $allowed = $customers->filter(fn (Customer $customer): bool => $customer->canReceiveMarketing());
        if ($allowed->isEmpty()) {
            throw ValidationException::withMessages(['mailing' => 'Никто из выбранных не давал согласия на рассылку — письмо отправить некому.']);
        }

        return DB::transaction(function () use ($subject, $body, $promo, $allowed, $customers, $by): Mailing {
            $mailing = Mailing::query()->create([
                'channel' => 'email', 'subject' => mb_substr($subject, 0, 200), 'body' => mb_substr($body, 0, 20000),
                'promo_kind' => $promo['kind'] ?? null, 'promo_value' => isset($promo['value']) ? (int) $promo['value'] : null,
                'promo_days' => isset($promo['days']) ? (int) $promo['days'] : null,
                'status' => Mailing::SENDING, 'created_by' => mb_substr($by, 0, 120),
                'total' => $allowed->count(),
            ]);
            foreach ($allowed as $customer) {
                $code = $promo ? $this->promoCodes->issue($promo + ['note' => 'Рассылка «'.mb_substr($subject, 0, 120).'»'], $customer, $by, $mailing) : null;
                $mailing->recipients()->create(['customer_id' => $customer->id, 'email' => $customer->email,
                    'status' => MailingRecipient::QUEUED, 'promo_code_id' => $code?->id]);
            }
            foreach ($customers->diffKeys($allowed) as $customer) {
                $mailing->recipients()->create(['customer_id' => $customer->id, 'email' => (string) $customer->email,
                    'status' => MailingRecipient::SKIPPED, 'error' => 'нет согласия на рассылку']);
            }

            return $mailing;
        });
    }

    /** Пробное письмо себе — как увидит клиент, с примером промокода. */
    public function sendTest(array $data, string $email): void
    {
        $mailing = new Mailing(['subject' => (string) ($data['subject'] ?? ''), 'body' => (string) ($data['body'] ?? '')]);
        $promo = isset($data['promo']['kind'])
            ? new PromoCode(['code' => 'LIVSI-ПРИМЕР', 'kind' => $data['promo']['kind'], 'value' => (int) ($data['promo']['value'] ?? 0),
                'min_total' => $data['promo']['min_total'] ?? null,
                'expires_at' => ! empty($data['promo']['days']) ? now()->addDays((int) $data['promo']['days']) : null])
            : null;
        Mail::to($email)->send(new MarketingMail($mailing, null, $promo, url('/')));
    }

    /** Отправить очередную пачку писем. Возвращает, сколько ушло. */
    public function sendBatch(int $limit = self::BATCH): int
    {
        $sent = 0;
        $recipients = MailingRecipient::query()->where('status', MailingRecipient::QUEUED)
            ->with(['mailing', 'customer', 'promoCode'])->orderBy('id')->limit($limit)->get();
        foreach ($recipients as $recipient) {
            $customer = $recipient->customer;
            if (! $customer || ! $customer->canReceiveMarketing()) {
                $recipient->update(['status' => MailingRecipient::SKIPPED, 'error' => 'отписался до отправки']);

                continue;
            }
            try {
                Mail::to($recipient->email)->send(new MarketingMail($recipient->mailing, $customer->name, $recipient->promoCode,
                    $this->unsubscribeUrl($customer)));
                $recipient->update(['status' => MailingRecipient::SENT, 'sent_at' => now(), 'error' => null]);
                $sent++;
            } catch (Throwable $exception) {
                $recipient->update(['status' => MailingRecipient::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 255)]);
                Log::warning('Письмо рассылки не ушло.', ['mailing' => $recipient->mailing_id, 'error' => mb_substr($exception->getMessage(), 0, 200)]);
            }
        }
        foreach ($recipients->pluck('mailing')->unique('id') as $mailing) {
            $this->recount($mailing);
        }

        return $sent;
    }

    public function recount(Mailing $mailing): void
    {
        $counts = $mailing->recipients()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $mailing->update([
            'sent' => (int) ($counts[MailingRecipient::SENT] ?? 0),
            'failed' => (int) ($counts[MailingRecipient::FAILED] ?? 0),
            'status' => ($counts[MailingRecipient::QUEUED] ?? 0) > 0 ? Mailing::SENDING : Mailing::DONE,
            'finished_at' => ($counts[MailingRecipient::QUEUED] ?? 0) > 0 ? null : ($mailing->finished_at ?? now()),
        ]);
    }

    public function unsubscribeUrl(Customer $customer): string
    {
        return URL::signedRoute('unsubscribe', ['customer' => $customer->id]);
    }
}
