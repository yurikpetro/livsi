<?php

namespace App\Auth;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Вход через Яндекс ID.
 *
 * Заказчик просил кнопки Яндекса, VK и Сбера в дополнение к коду из СМС,
 * чтобы часть людей заходила без отправки сообщения (`04-questions-for-client.md`,
 * п. 1.1). Яндекс первый: приложение регистрируется самостоятельно, без
 * договора с банком.
 *
 * Прав запрашиваем три из пяти: почта нужна для чека по 54-ФЗ, имя —
 * чтобы не спрашивать его снова, телефон — ровно та экономия на СМС,
 * ради которой всё и затевалось. Портрет и дата рождения не запрошены:
 * в макете аватаров нет, поздравлений в объёме нет, а каждое лишнее право
 * — строка на экране согласия, то есть минус к конверсии кнопки.
 */
class YandexId
{
    public const PROVIDER = 'yandex';

    /** Права из настроек приложения. Список должен совпадать с тем, что там отмечено. */
    private const SCOPE = 'login:email login:info login:default_phone';

    public function isConfigured(): bool
    {
        return filled(config('livsi.yandex.client_id'))
            && filled(config('livsi.yandex.client_secret'));
    }

    /**
     * Адрес, куда отправляем человека.
     *
     * `state` — не украшение: без него чужой мог бы подсунуть свой код
     * авторизации и привязать наш аккаунт к своему Яндексу.
     */
    public function authUrl(string $state): string
    {
        $this->guard();

        return config('livsi.yandex.auth_url') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('livsi.yandex.client_id'),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => self::SCOPE,
            'state'         => $state,
        ]);
    }

    /**
     * Адрес возврата.
     *
     * Считается из маршрута, а не хранится в настройках: у провайдера он
     * зарегистрирован посимвольно, и второе место, где его можно поправить,
     * рано или поздно разъедется с первым.
     */
    public function redirectUri(): string
    {
        return route('auth.yandex.callback');
    }

    /** @throws RuntimeException если провайдер не отдал токен или данные */
    public function profile(string $code): SocialProfile
    {
        $this->guard();

        // redirect_uri в обмене кода Яндекс не требует: адрес у приложения
        // один и уже проверен на предыдущем шаге.
        $token = Http::asForm()
            ->timeout(15)
            ->post(config('livsi.yandex.token_url'), [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => config('livsi.yandex.client_id'),
                'client_secret' => config('livsi.yandex.client_secret'),
            ]);

        if ($token->failed() || blank($token->json('access_token'))) {
            throw new RuntimeException('Яндекс не выдал токен: ' . $token->status());
        }

        // Схема именно OAuth, а не Bearer — так у Яндекса.
        $info = Http::withHeaders(['Authorization' => 'OAuth ' . $token->json('access_token')])
            ->timeout(15)
            ->get(config('livsi.yandex.info_url'), ['format' => 'json']);

        if ($info->failed() || blank($info->json('id'))) {
            throw new RuntimeException('Яндекс не отдал данные пользователя: ' . $info->status());
        }

        return new SocialProfile(
            provider: self::PROVIDER,
            id: (string) $info->json('id'),
            email: $info->json('default_email'),
            name: $this->name($info->json()),
            phone: Phone::format($info->json('default_phone.number')),
        );
    }

    /**
     * Имя для заказа.
     *
     * `real_name` бывает пустым, `display_name` — это логин вроде
     * `ivan.petrov.1990`, в поле «Имя» такое класть нельзя. Поэтому
     * собираем сами и, если ничего не собралось, оставляем пусто:
     * пусть человек впишет, а не правит за нами.
     */
    private function name(array $info): ?string
    {
        $name = trim(implode(' ', array_filter([
            $info['first_name'] ?? null,
            $info['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : null;
    }

    private function guard(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Не заданы ключи Яндекс ID: YANDEX_CLIENT_ID и YANDEX_CLIENT_SECRET.');
        }
    }
}
