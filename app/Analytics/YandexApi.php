<?php

namespace App\Analytics;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Запрос к API Яндекса от имени владельца и понятный текст, если не вышло.
 */
abstract class YandexApi
{
    public function __construct(protected readonly YandexOAuth $oauth) {}

    /** Название сервиса для текста ошибок: «Метрика», «Вебмастер». */
    abstract protected function service(): string;

    /** Какую галочку отметить в приложении, если доступа нет. */
    abstract protected function scopeHint(): string;

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query  повторяющиеся параметры — списком
     * @return array<string, mixed>
     *
     * @throws AnalyticsException
     */
    protected function request(string $method, string $url, array $query = []): array
    {
        $token = $this->oauth->accessToken();
        $qs = self::query($query);

        try {
            $response = Http::withHeaders(['Authorization' => 'OAuth '.$token])
                ->acceptJson()->timeout(25)
                ->send($method, $url.($qs !== '' ? '?'.$qs : ''));
        } catch (Throwable $e) {
            throw new AnalyticsException($this->service().' не ответил(а) вовремя. Нажмите «Обновить» через минуту.', previous: $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        throw new AnalyticsException($this->explain($response));
    }

    /**
     * Запрос, ответ которого разбирает вызывающий: для действий, где «уже
     * добавлено» или «квота кончилась» — не ошибка, а обычный исход.
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, mixed>|null  $json  тело запроса
     *
     * @throws AnalyticsException нет доступа или Яндекс не ответил
     */
    protected function raw(string $method, string $url, array $query = [], ?array $json = null): Response
    {
        $token = $this->oauth->accessToken();
        $qs = self::query($query);

        try {
            $request = Http::withHeaders(['Authorization' => 'OAuth '.$token])->acceptJson()->timeout(25);
            $response = $json === null
                ? $request->send($method, $url.($qs !== '' ? '?'.$qs : ''))
                : $request->send($method, $url.($qs !== '' ? '?'.$qs : ''), ['json' => $json]);
        } catch (Throwable $e) {
            throw new AnalyticsException($this->service().' не ответил(а) вовремя.', previous: $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new AnalyticsException($this->explain($response));
        }

        return $response;
    }

    private function explain(Response $response): string
    {
        $detail = (string) ($response->json('message') ?? $response->json('error_message') ?? $response->json('errors.0.message') ?? '');
        foreach (AnalyticsSettings::allSecrets() as $secret) {
            $detail = str_replace($secret, '***', $detail);
        }
        $detail = mb_substr($detail, 0, 200);

        return match (true) {
            $response->status() === 401 => 'Яндекс не принял доступ сайта. Нажмите «Подключить Яндекс» на странице «Подключение» ещё раз.',
            $response->status() === 403 => $this->service().': нет доступа. '.$this->scopeHint().($detail !== '' ? ' (Яндекс: '.$detail.')' : ''),
            $response->status() === 429 => $this->service().': Яндекс просит не так часто — откройте страницу через несколько минут.',
            default => $this->service().' ответил(а) ошибкой '.$response->status().($detail !== '' ? ': '.$detail : '.'),
        };
    }

    /** Строка запроса, где один параметр может повторяться: query_indicator=A&query_indicator=B. */
    public static function query(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $parts);
    }
}
