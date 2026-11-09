<?php

namespace Tests\Feature;

use App\Jobs\SendBusinessTelegram;
use App\Models\LeadRequest;
use App\Monitoring\BusinessReport;
use App\Monitoring\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Телеграм на боевом сервере иногда принимает сообщение, а ответ не
 * присылает дольше таймаута. Повтор такой отправки — дубль в группе.
 */
class TelegramDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private int $sent = 0;

    private string $error = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'livsi.telegram.token' => 'bot-token', 'livsi.telegram.chat_id' => '-1004404612976',
            'livsi.telegram.api_url' => 'https://api.telegram.org', 'livsi.telegram.topics.issues' => 7,
        ]);
    }

    /** Заглушки Http::fake копятся, поэтому ошибка меняется через свойство, а не новой заглушкой. */
    private function fake(string $error): void
    {
        $this->sent = 0;
        $this->error = $error;
        Http::fake(['api.telegram.org/*' => function () {
            $this->sent++;
            throw new ConnectionException($this->error);
        }]);
    }

    public function test_silent_answer_after_the_message_went_out_is_not_repeated(): void
    {
        $this->fake('cURL error 28: Operation timed out after 5002 milliseconds with 0 bytes received');

        try {
            app(Telegram::class)->send('<b>Клиент просит вернуть заказ</b>', 'issues');
            $this->fail('Ошибка должна дойти до вызывающего.');
        } catch (ConnectionException $exception) {
            $this->assertTrue(Telegram::maybeDelivered($exception));
        }
        $this->assertSame(1, $this->sent);
    }

    public function test_failed_connection_is_still_retried(): void
    {
        $this->fake('cURL error 28: Connection timed out after 2001 milliseconds');

        try {
            app(Telegram::class)->send('<b>Тест</b>', 'issues');
        } catch (ConnectionException $exception) {
            $this->assertFalse(Telegram::maybeDelivered($exception));
        } catch (RuntimeException) {
        }
        $this->assertSame(3, $this->sent);
    }

    public function test_background_job_does_not_resend_a_probably_delivered_message(): void
    {
        $lead = LeadRequest::create(['name' => 'Анна', 'contact' => '@anna', 'type' => LeadRequest::TYPE_WHOLESALE, 'consent_at' => now()]);
        $this->fake('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');

        $job = (new SendBusinessTelegram('lead', $lead->id, 'issues'))->withFakeQueueInteractions();
        $job->handle(app(Telegram::class), app(BusinessReport::class));

        $job->assertNotReleased();
        $this->assertSame(1, $this->sent);

        $this->fake('cURL error 7: Failed to connect to api.telegram.org port 443');
        $job = (new SendBusinessTelegram('lead', $lead->id, 'issues'))->withFakeQueueInteractions();
        $job->handle(app(Telegram::class), app(BusinessReport::class));
        $job->assertReleased(60);
    }
}
