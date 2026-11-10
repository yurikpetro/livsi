<?php

namespace App\Http\Controllers\Admin;

use App\Analytics\AnalyticsException;
use App\Analytics\AnalyticsSettings;
use App\Analytics\Webmaster;
use App\Analytics\YandexOAuth;
use App\Filament\Pages\Analytics\AnalyticsConnection;
use App\Http\Controllers\Controller;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * «Подключить Яндекс» в разделе «Аналитика сайта»: отправляем владельца
 * на страницу разрешения Яндекса и принимаем его обратно с кодом.
 */
class YandexAnalyticsController extends Controller
{
    public function __construct(private readonly YandexOAuth $oauth) {}

    public function connect(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        if (! $this->oauth->isConfigured()) {
            Notification::make()->title('Сначала вставьте ClientID и Client secret и нажмите «Сохранить»')->warning()->send();

            return redirect()->to(AnalyticsConnection::getUrl());
        }

        // state — защита от подмены: без него чужой мог бы подсунуть сайту
        // свой код и подключить к аналитике свой Яндекс.
        $state = Str::random(40);
        $request->session()->put('analytics_oauth_state', $state);

        return redirect()->away($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $expected = (string) $request->session()->pull('analytics_oauth_state', '');
        $back = redirect()->to(AnalyticsConnection::getUrl());

        if ($request->filled('error')) {
            Notification::make()->title('Яндекс не дал доступ')
                ->body($request->query('error') === 'access_denied'
                    ? 'На странице Яндекса нажали «Отмена». Нажмите «Подключить Яндекс» ещё раз и выберите «Разрешить».'
                    : 'Ответ Яндекса: '.mb_substr((string) ($request->query('error_description') ?: $request->query('error')), 0, 200))
                ->danger()->persistent()->send();

            return $back;
        }

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state')) || ! $request->filled('code')) {
            Notification::make()->title('Ссылка подключения устарела')
                ->body('Нажмите «Подключить Яндекс» ещё раз — прямо на странице «Подключение».')
                ->warning()->persistent()->send();

            return $back;
        }

        try {
            $this->oauth->exchange((string) $request->query('code'));
        } catch (AnalyticsException $e) {
            Notification::make()->title('Не удалось подключить Яндекс')->body($e->getMessage())->danger()->persistent()->send();

            return $back;
        }

        Notification::make()->title('Яндекс подключён')->body($this->afterConnect())->success()->persistent()->send();

        return $back;
    }

    /**
     * Сразу после подключения: если код Вебмастера на сайте не задан —
     * берём его из Вебмастера. Ничего не вышло — не беда, это видно на
     * странице, а подключение уже состоялось.
     */
    private function afterConnect(): string
    {
        try {
            $verification = app(Webmaster::class)->verification();
            if ($verification['uin'] && AnalyticsSettings::verificationCode() === null) {
                AnalyticsSettings::setVerificationCode($verification['uin']);

                return 'Код Вебмастера подставлен на сайт сам. Осталось выбрать счётчик Метрики ниже.';
            }
        } catch (Throwable) {
            // Вебмастер не отдал данные — причина будет видна в блоке Вебмастера.
        }

        return 'Осталось выбрать счётчик Метрики ниже и нажать «Сохранить».';
    }
}
