<?php

namespace App\Mail;

use App\Models\Mailing;
use App\Models\PromoCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Рекламное письмо из рассылки. Уходит только тем, кто дал согласие;
 * в каждом — ссылка «отписаться» и заголовок List-Unsubscribe
 * (38-ФЗ «О рекламе», ст. 18; почтовые службы без него режут в спам).
 */
class MarketingMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Mailing $mailing,
        public ?string $name,
        public ?PromoCode $promo,
        public string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailing->subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.marketing');
    }
}
