<?php

namespace App\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Доступ сайта к Метрике и Вебмастеру владельца.
 *
 * Владелец один раз создаёт приложение на oauth.yandex.ru, вставляет его
 * ClientID и Client secret в админку и нажимает «Подключить Яндекс».
 * Яндекс спрашивает разрешение и показывает код, владелец вставляет его
 * в админку, сайт меняет код на токен. Токен живёт около года; за месяц до конца сайт сам продлевает
 * его ключом обновления — заново ничего нажимать не нужно.
 */
class YandexOAuth
{
    public const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';

    public const TOKEN_URL = 'https://oauth.yandex.ru/token';

    /** Доступы, которые нужно отметить в приложении. */
    public const SCOPES = ['metrika:read', 'webmaster:hostinfo', 'webmaster:verify'];

    /** Продлеваем, когда до конца срока осталось меньше этого. */
    private const REFRESH_BEFORE_DAYS = 30;

    public function isConfigured(): bool
    {
        return AnalyticsSettings::clientId() !== null && AnalyticsSettings::secret('oauth_client_secret') !== null;
    }

    public function connected(): bool
    {
        return AnalyticsSettings::secret('access_token') !== null;
    }

    /**
     * Страница разрешения Яндекса.
     *
     * Права не перечисляем: Яндекс выдаст те, что отмечены в приложении.
     * Иначе одна неотмеченная галочка давала бы ошибку invalid_scope вместо
     * понятного «нет доступа к Вебмастеру» на странице.
     *
     * Адрес возврата тоже не передаём — Яндекс берёт тот, что в приложении.
     * У приложения «для доступа к API» это его страница с кодом: владелец
     * копирует код и вставляет в админку. Если приложение заведено «для
     * веб-сервисов» с адресом возврата на наш сайт, код придёт сам.
     */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => AnalyticsSettings::clientId(),
            'state' => $state,
            'force_confirm' => 'yes',
        ]);
    }

    /** Код из адреса возврата → токен. @throws AnalyticsException */
    public function exchange(string $code): void
    {
        $this->store($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]));
        AnalyticsSettings::setOptions([
            'connected_at' => now()->toIso8601String(),
            // Сайт в Вебмастере ищется заново: аккаунт мог смениться.
            'webmaster_user_id' => null,
            'webmaster_host_id' => null,
        ]);
    }

    /**
     * Токен для запроса; если срок подходит к концу — сначала продлеваем.
     * Не продлился (Яндекс недоступен) — работаем со старым, пока он жив.
     *
     * @throws AnalyticsException
     */
    public function accessToken(): string
    {
        $token = AnalyticsSettings::secret('access_token');
        if ($token === null) {
            throw new AnalyticsException('Яндекс не подключён: откройте «Аналитика сайта» → «Подключение» и нажмите «Подключить Яндекс».');
        }

        $expires = $this->expiresAt();
        if ($expires !== null && $expires->lt(now()->addDays(self::REFRESH_BEFORE_DAYS))
            && AnalyticsSettings::secret('refresh_token') !== null && $this->isConfigured()) {
            try {
                $this->refresh();
                $token = (string) AnalyticsSettings::secret('access_token');
            } catch (Throwable $e) {
                Log::warning('Не удалось продлить доступ к Яндексу для аналитики.', ['error' => $e->getMessage()]);
                if ($expires->isPast()) {
                    throw new AnalyticsException('Срок доступа к Яндексу закончился, а продлить его не вышло. Нажмите «Подключить Яндекс» ещё раз.');
                }
            }
        }

        return $token;
    }

    /** @throws AnalyticsException */
    public function refresh(): void
    {
        $refresh = AnalyticsSettings::secret('refresh_token');
        if ($refresh === null) {
            throw new AnalyticsException('Нет ключа обновления — нажмите «Подключить Яндекс» ещё раз.');
        }
        $this->store($this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ]));
    }

    public function disconnect(): void
    {
        AnalyticsSettings::forgetSecrets(['access_token', 'refresh_token']);
        AnalyticsSettings::setOptions([
            'connected_at' => null,
            'token_expires_at' => null,
            'webmaster_user_id' => null,
            'webmaster_host_id' => null,
        ]);
    }

    public function expiresAt(): ?Carbon
    {
        $value = AnalyticsSettings::option('token_expires_at');

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /** @param array<string, string> $params @return array<string, mixed> */
    private function tokenRequest(array $params): array
    {
        if (! $this->isConfigured()) {
            throw new AnalyticsException('Сначала вставьте ClientID и Client secret приложения и нажмите «Сохранить».');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params + [
                'client_id' => AnalyticsSettings::clientId(),
                'client_secret' => AnalyticsSettings::secret('oauth_client_secret'),
            ]);
        } catch (Throwable $e) {
            throw new AnalyticsException('Яндекс не ответил. Попробуйте через минуту.', previous: $e);
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new AnalyticsException(match ((string) $response->json('error')) {
                'invalid_client' => 'Яндекс не узнал приложение: проверьте ClientID и Client secret (oauth.yandex.ru → ваше приложение).',
                'invalid_grant' => 'Код подтверждения устарел или уже использован. Нажмите «Подключить Яндекс» ещё раз.',
                'bad_verification_code' => 'Код подтверждения устарел или уже использован. Нажмите «Подключить Яндекс» ещё раз.',
                default => 'Яндекс не выдал доступ (ответ '.$response->status().'): '.mb_substr((string) ($response->json('error_description') ?? $response->json('error') ?? ''), 0, 200),
            });
        }

        return (array) $response->json();
    }

    /** @param array<string, mixed> $data */
    private function store(array $data): void
    {
        AnalyticsSettings::setSecrets([
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
        ]);
        $seconds = (int) ($data['expires_in'] ?? 0);
        AnalyticsSettings::setOptions([
            'token_expires_at' => $seconds > 0 ? now()->addSeconds($seconds)->toIso8601String() : null,
        ]);
    }
}
