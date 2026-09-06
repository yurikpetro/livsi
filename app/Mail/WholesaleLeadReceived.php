<?php

namespace App\Mail;

use App\Models\LeadRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Уведомление менеджеру о заявке на оптовое партнёрство.
 *
 * Отдельное от контрактного производства: другие поля, другой сценарий
 * работы и другая тема письма, чтобы в почте они не смешивались.
 */
class WholesaleLeadReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LeadRequest $lead)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Оптовое партнёрство — заявка №' . $this->lead->id,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.wholesale-lead');
    }
}
