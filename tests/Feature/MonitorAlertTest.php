<?php

namespace Tests\Feature;

use App\Monitoring\ErrorReport;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

/**
 * Оповещения о сбоях в Телеграм.
 *
 * Проверяется не «отправился ли запрос», а то, ради чего канал заведён:
 * сообщение уходит в нужный топик, одинаковые ошибки не сыплются очередью,
 * секреты в группу не попадают, и — главное — неудачная отправка не роняет
 * тот самый запрос, в котором произошла ошибка.
 */
class MonitorAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'livsi.telegram.token' => 'ключ-бота-для-теста',
            'livsi.telegram.chat_id' => '-1004404612976',
            'livsi.telegram.topics.errors' => 4,
            'livsi.telegram.repeat_after' => 600,
            'logging.channels.telegram.level' => 'error',
        ]);

        Log::forgetChannel('telegram');
    }

    public function test_error_goes_to_the_right_chat_and_topic(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Не удалось создать платёж.', [
            'order' => 'LV-000042',
            'error' => 'провайдер ответил 500',
        ]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && $body['chat_id'] === '-1004404612976'
                && $body['message_thread_id'] === 4
                && $body['parse_mode'] === 'HTML'
                && str_contains($body['text'], 'Не удалось создать платёж.')
                && str_contains($body['text'], 'LV-000042')
                && str_contains($body['text'], 'провайдер ответил 500');
        });
    }

    public function test_exception_brings_its_class_and_place(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Всё сломалось.', [
            'exception' => new RuntimeException('подробности'),
        ]);

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], 'RuntimeException')
            && str_contains($request->data()['text'], 'tests/Feature/MonitorAlertTest.php'));
    }

    /**
     * Ради этого всё и делалось: в группу уходит любая ошибка сайта,
     * а не только та, которую кто-то не поленился записать руками.
     * Канал подключён к стопке — значит, ловится и то, что упало само.
     */
    public function test_unhandled_exception_on_the_site_reaches_the_group(): void
    {
        Http::fake($this->ok());

        config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['telegram']]);
        Log::forgetChannel();

        // Под PHPUnit интерпретатор запущен из консоли, и отчёт честно
        // пишет «консоль» вместо адреса страницы. Подменяем ровно этот
        // признак — иначе ветка веб-запроса не проверяется никогда,
        // а на боевом контуре работает только она.
        (function () {
            $this->isRunningInConsole = false;
        })->call($this->app);

        Route::get('/_boom', fn () => throw new RuntimeException('взрыв на витрине'));

        $this->get('/_boom')->assertServerError();

        Http::assertSent(function (Request $request) {
            $text = $request->data()['text'];

            return str_contains($text, 'взрыв на витрине')
                && str_contains($text, 'RuntimeException')
                && str_contains($text, 'GET ')
                && str_contains($text, '/_boom')
                && str_contains($text, 'гость · 127.0.0.1')
                && str_contains($text, "\n\n");
        });
    }

    public function test_warnings_stay_out_of_the_group(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->warning('Уведомление об оплате с постороннего адреса.');

        Http::assertNothingSent();
    }

    /**
     * Порог берётся из LOG_TELEGRAM_LEVEL, а не зашит в обработчике:
     * иначе попытка приглушить группу до «критично» молча ничего
     * не изменила бы.
     */
    public function test_threshold_comes_from_the_channel_settings(): void
    {
        config(['logging.channels.telegram.level' => 'warning']);
        Log::forgetChannel('telegram');

        Http::fake($this->ok());

        Log::channel('telegram')->warning('Уведомление об оплате с постороннего адреса.');

        Http::assertSentCount(1);
    }

    public function test_same_error_is_sent_once_per_window(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Не удалось создать платёж.');
        Log::channel('telegram')->error('Не удалось создать платёж.');
        Log::channel('telegram')->error('Не удалось создать платёж.');

        Http::assertSentCount(1);
    }

    public function test_next_message_counts_what_was_held_back(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Не удалось создать платёж.');
        Log::channel('telegram')->error('Не удалось создать платёж.');
        Log::channel('telegram')->error('Не удалось создать платёж.');

        // Окно молчания истекло: счётчик повторов переживает его намеренно,
        // иначе масштаб сбоя виден не был бы.
        Cache::forget($this->keyFor('Не удалось создать платёж.'));

        Log::channel('telegram')->error('Не удалось создать платёж.');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->data()['text'], 'и ещё 2 таких же'));
    }

    /** Числа в тексте различаются от заказа к заказу, ошибка при этом одна. */
    public function test_numbers_in_the_text_do_not_split_the_same_error(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Нет квоты по позиции 17.');
        Log::channel('telegram')->error('Нет квоты по позиции 941.');

        Http::assertSentCount(1);
    }

    public function test_secrets_never_reach_the_group(): void
    {
        config(['livsi.yookassa.secret_key' => 'test_очень-секретный-ключ']);

        Http::fake($this->ok());

        Log::channel('telegram')->error('Отказ провайдера.', [
            'ключ' => 'test_очень-секретный-ключ',
            'client_secret' => 'da74dee59b594abb96f74fe24917824d',
        ]);

        Http::assertSent(function (Request $request) {
            $text = $request->data()['text'];

            return ! str_contains($text, 'test_очень-секретный-ключ')
                && ! str_contains($text, 'da74dee59b594abb96f74fe24917824d')
                && str_contains($text, '***');
        });
    }

    /**
     * Самое важное свойство: обработчик подключён к стопке каналов,
     * у которой ignore_exceptions выключено. Если он бросит исключение,
     * упадёт не оповещение, а запрос покупателя.
     */
    public function test_telegram_failure_does_not_break_the_request(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        Log::channel('telegram')->error('Не удалось создать платёж.');

        $this->assertTrue(true);
    }

    public function test_unreachable_telegram_does_not_break_the_request(): void
    {
        Http::fake(fn () => throw new ConnectionException('соединение не установлено'));

        Log::channel('telegram')->error('Не удалось создать платёж.');

        $this->assertTrue(true);
    }

    /**
     * Несработавшее оповещение обязано оставить след в файле: иначе
     * получается та самая тишина, против которой канал и заводили.
     */
    public function test_failed_alert_leaves_a_trace_in_the_log(): void
    {
        $path = storage_path('logs/monitor-fallback-test.log');
        @unlink($path);

        config([
            'livsi.telegram.fallback_channel' => 'проверочный',
            'logging.channels.проверочный' => ['driver' => 'single', 'path' => $path],
        ]);

        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        Log::channel('telegram')->error('Не удалось создать платёж.');

        $written = file_get_contents($path);
        @unlink($path);

        $this->assertStringContainsString('Оповещение в Телеграм не ушло.', $written);
        $this->assertStringContainsString('chat not found', $written);
    }

    /**
     * С боевого сервера каждое пятое соединение с Телеграмом срывается
     * на рукопожатии. Без повтора терялась бы пятая часть оповещений —
     * и заметить это было бы нечем.
     */
    public function test_lost_connection_is_retried(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('соединение не установлено');
            }

            return Http::response(['ok' => true, 'result' => []]);
        });

        Log::channel('telegram')->error('Не удалось создать платёж.');

        $this->assertSame(2, $attempts);
    }

    /** Отказ самого Телеграма повторять незачем: ответ будет тот же. */
    public function test_refusal_from_telegram_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        Log::channel('telegram')->error('Не удалось создать платёж.');

        Http::assertSentCount(1);
    }

    public function test_nothing_is_sent_while_the_bot_is_not_configured(): void
    {
        config(['livsi.telegram.token' => null]);

        Http::fake($this->ok());

        Log::channel('telegram')->error('Не удалось создать платёж.');

        Http::assertNothingSent();
    }

    /**
     * Длинное сообщение уходит простым текстом, а не ломаной разметкой.
     *
     * Куски сообщения обрезаны по длине заранее, поэтому перерасти предел
     * текст может только на экранировании: каждый амперсанд превращается
     * в пять символов, и 900 таких дают 4500.
     */
    public function test_oversized_message_falls_back_to_plain_text(): void
    {
        Http::fake($this->ok());

        Log::channel('telegram')->error('Сбой.', [
            'выдача' => str_repeat('&', 900),
        ]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return ! isset($body['parse_mode'])
                && mb_strlen($body['text']) <= 4096;
        });
    }

    public function test_check_command_verifies_bot_chat_and_topic(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'livsi_monitor_bot']]),
            '*/getChat' => Http::response(['ok' => true, 'result' => ['title' => 'LIVSI', 'is_forum' => true]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('monitor:check')
            ->expectsOutputToContain('@livsi_monitor_bot')
            ->expectsOutputToContain('LIVSI')
            ->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_check_command_reports_a_wrong_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->artisan('monitor:check')->assertFailed();
    }

    private function ok(): array
    {
        return ['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])];
    }

    private function keyFor(string $message): string
    {
        $record = new LogRecord(new DateTimeImmutable, 'testing', Level::Error, $message);

        return 'monitor:alert:'.(new ErrorReport($record))->fingerprint();
    }
}
