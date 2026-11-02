<?php

namespace App\Auth;

use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VkId
{
    /** VK и Mail возвращают идентификатор в общем пространстве VK ID. */
    public const PROVIDER = 'vk';

    public function isConfigured(): bool
    {
        return filled(config('livsi.vk.client_id'));
    }

    public function redirectUri(): string
    {
        return config('livsi.vk.redirect_uri') ?: route('auth.vk.callback');
    }

    /** Сессия должна начинаться на том же домене, куда вернётся VK ID. */
    public function canonicalPageUrl(Request $request): ?string
    {
        $parts = parse_url($this->redirectUri());
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $request->getSchemeAndHttpHost() === $origin ? null : $origin.$request->getRequestUri();
    }

    public function authUrl(string $state, string $verifier, string $provider): string
    {
        return config('livsi.vk.auth_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('livsi.vk.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => 'email phone',
            'provider' => $provider === 'mail' ? 'mail_ru' : 'vkid',
            'prompt' => $provider === 'mail' ? 'login' : '',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function profile(string $code, string $deviceId, string $verifier, string $state): SocialProfile
    {
        $token = Http::asForm()->timeout(15)->post(config('livsi.vk.token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('livsi.vk.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
            'device_id' => $deviceId,
            'code_verifier' => $verifier,
            'state' => $state,
        ]);

        $accessToken = $token->json('access_token');
        $userId = $token->json('user_id');
        $returnedState = $token->json('state');

        if ($token->failed() || $token->json('error') !== null
            || ! is_string($accessToken) || $accessToken === ''
            || (! is_string($userId) && ! is_int($userId)) || (string) $userId === ''
            || ! is_string($returnedState) || ! hash_equals($state, $returnedState)) {
            throw new RuntimeException('VK ID не выдал корректный токен.');
        }

        $info = Http::asForm()->timeout(15)->post(config('livsi.vk.info_url'), [
            'client_id' => config('livsi.vk.client_id'),
            'access_token' => $accessToken,
        ]);

        $user = $info->json('user');
        $profileId = is_array($user) ? ($user['user_id'] ?? null) : null;

        if ($info->failed() || $info->json('error') !== null || ! is_array($user)
            || (! is_string($profileId) && ! is_int($profileId)) || (string) $profileId !== (string) $userId) {
            throw new RuntimeException('VK ID не отдал корректный профиль.');
        }

        $name = trim(implode(' ', array_filter([
            $this->text($user['first_name'] ?? null),
            $this->text($user['last_name'] ?? null),
        ])));
        $email = $this->email($user['email'] ?? null) ?? $this->email($token->json('email'));
        $idToken = $this->text($token->json('id_token'));
        $publicInfoStatus = null;

        if ($email === null && $idToken !== null) {
            try {
                $publicInfo = Http::asForm()->timeout(15)->post(config('livsi.vk.public_info_url'), [
                    'client_id' => config('livsi.vk.client_id'),
                    'id_token' => $idToken,
                ]);
                $publicInfoStatus = $publicInfo->status();
                $publicUser = $publicInfo->json('user');
                $publicId = is_array($publicUser) ? ($publicUser['user_id'] ?? null) : null;

                if ($publicInfo->successful() && $publicInfo->json('error') === null
                    && (is_string($publicId) || is_int($publicId)) && (string) $publicId === (string) $userId) {
                    $email = $this->email($publicUser['email'] ?? null);
                }
            } catch (Throwable) {
                $publicInfoStatus = 0;
            }
        }

        if ($email === null) {
            /** Только признаки и известные права: без токенов, идентификаторов и контактов. */
            Log::warning('VK ID: почта не получена.', [
                'granted_scopes' => array_values(array_intersect(
                    ['vkid.personal_info', 'email', 'phone'],
                    preg_split('/[\s,]+/', $this->text($token->json('scope')) ?? '') ?: [],
                )),
                'profile_email_present' => array_key_exists('email', $user),
                'token_email_present' => $token->json('email') !== null,
                'id_token_present' => $idToken !== null,
                'public_info_status' => $publicInfoStatus,
            ]);
        }

        return new SocialProfile(
            provider: self::PROVIDER,
            id: (string) $userId,
            email: $email,
            name: $name !== '' ? $name : null,
            phone: Phone::format($this->text($user['phone'] ?? null)),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->text($value);

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
