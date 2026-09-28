<?php

namespace App\Monitoring;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Бот, который пишет в рабочую группу.
 *
 * Группа сделана с топиками, и у каждого топика свой номер — он же
 * `message_thread_id`. Номер виден в ссылке на топик: в
 * `https://t.me/c/4404612976/4` это чат `-1004404612976` и топик `4`.
 * Приставка `-100` обязательна, число из ссылки само по себе API не примет.
 *
 * Сюда вынесена только доставка. Что именно писать — дело вызывающего:
 * так к этому же боту можно будет подключить топики «Заказы» и «Заявки»,
 * не трогая формат ошибок.
 */
class Telegram
{
    /** Длиннее Телеграм сообщение не принимает. */
    public const LIMIT = 4096;

    public function isConfigured(): bool
    {
        return filled(config('livsi.telegram.token'))
            && filled(config('livsi.telegram.chat_id'));
    }

    /** Номер топика по имени из настроек; null — писать в общую ленту. */
    public function topic(?string $name): ?int
    {
        $id = config('livsi.telegram.topics.'.($name ?: 'errors'));

        return filled($id) ? (int) $id : null;
    }

    /**
     * Отправить сообщение. Разметка — HTML, подставляемые куски
     * вызывающий обязан пропустить через ErrorReport::escape().
     *
     * Бросает исключение: команде проверки нужна настоящая причина отказа.
     * Обработчик лога, наоборот, всё глотает — там сообщать о сбое некому.
     */
    public function send(string $html, ?string $topic = null): void
    {
        $text = $html;
        $mode = 'HTML';

        // Обрезка посередине тега ломает разметку, и Телеграм отвечает
        // «can't parse entities» — то есть длинная ошибка не дошла бы
        // вообще. Поэтому переросшее сообщение отправляем простым
        // текстом: содержание важнее оформления.
        if (mb_strlen($text) > self::LIMIT) {
            $text = mb_substr(
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                0,
                self::LIMIT - 1,
            ).'…';
            $mode = null;
        }

        $payload = array_filter([
            'chat_id' => (string) config('livsi.telegram.chat_id'),
            'text' => $text,
            'parse_mode' => $mode,
            'message_thread_id' => $this->topic($topic),
            'link_preview_options' => ['is_disabled' => true],
        ], fn ($value) => $value !== null);

        $this->call('sendMessage', $payload);
    }

    /** @return array<string, mixed> */
    public function call(string $method, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Бот не настроен: нет TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID.');
        }

        $request = Http::baseUrl($this->endpoint())
            ->acceptJson()
            ->connectTimeout((float) config('livsi.telegram.connect_timeout', 2))
            ->timeout((float) config('livsi.telegram.timeout', 5))
            // Повтор только когда соединение не установилось. Отказ самого
            // Телеграма («неизвестный чат») повторять незачем — ответ будет
            // тот же, а секунды тратятся внутри запроса покупателя.
            ->retry(
                times: (int) config('livsi.telegram.tries', 3),
                sleepMilliseconds: 250,
                when: fn (Throwable $e) => $e instanceof ConnectionException,
                throw: false,
            );

        // На боевом сервере IPv4 до api.telegram.org не проходит — тот же
        // фильтр Timeweb, из-за которого до нас не доходят проверяющие
        // Let's Encrypt. Через IPv6 всё работает за десятые доли секунды.
        // Без явного выбора curl пробует оба адреса и иногда ставит на
        // мёртвый: соединение висит до таймаута, и оповещение пропадает.
        if ($version = config('livsi.telegram.ip_version')) {
            $request->withOptions(['force_ip_resolve' => $version]);
        }

        $response = $request->post('/'.$method, $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Телеграм отклонил запрос: '.($body['description'] ?? 'ответ '.$response->status()),
            );
        }

        return $body['result'] ?? [];
    }

    /**
     * Токен стоит в адресе — так устроен API Телеграма. Значит, он попадёт
     * в текст ошибки соединения, а оттуда в лог; ErrorReport его вычищает.
     */
    private function endpoint(): string
    {
        return rtrim((string) config('livsi.telegram.api_url'), '/')
            .'/bot'.config('livsi.telegram.token');
    }
}
