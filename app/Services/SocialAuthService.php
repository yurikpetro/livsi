<?php

namespace App\Services;

use App\Auth\SocialProfile;
use App\Models\Order;
use App\Models\SocialAccount;
use App\Models\User;
use App\Monitoring\BusinessNotifications;
use App\Support\Phone;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Кого впустить по ответу внешнего провайдера.
 *
 * По решению заказчика от 15.10.2026 разные способы входа связываются
 * с одним клиентом по email или телефону из ответов провайдеров.
 * Телефон привязки хранится отдельно от редактируемого контакта клиента.
 * Служебные аккаунты и неоднозначные совпадения автоматически не связываются.
 */
class SocialAuthService
{
    public function __construct(private readonly BusinessNotifications $businessNotifications) {}

    public function login(SocialProfile $profile): User
    {
        $email = $this->email($profile->email);
        $phone = Phone::format($profile->phone);
        $keys = ['provider:'.$profile->provider.':'.$profile->id];

        if ($email !== null) {
            $keys[] = 'email:'.$email;
        }

        if ($phone !== null) {
            $keys[] = 'phone:'.$phone;
        }

        sort($keys, SORT_STRING);
        $locks = [];

        try {
            foreach ($keys as $key) {
                $lock = Cache::lock('social-auth:'.hash('sha256', $key), 30);
                $lock->block(5);
                $locks[] = $lock;
            }

            return DB::transaction(fn (): User => $this->resolve($profile, $email, $phone), 3);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function resolve(SocialProfile $profile, ?string $email, ?string $phone): User
    {
        $account = SocialAccount::where('provider', $profile->provider)
            ->where('provider_user_id', $profile->id)
            ->lockForUpdate()
            ->first();

        if ($account) {
            $user = User::whereKey($account->user_id)->lockForUpdate()->firstOrFail();
            $this->guardCustomer($user);
            $this->fillGaps($user, $phone);
            $account->update([
                'email' => $email ?? $account->email,
                'phone' => $phone ?? $account->phone,
            ]);

            return $user;
        }

        $user = $this->matchingCustomer($email, $phone);
        $isNew = $user === null;

        if ($user !== null) {
            $this->guardCustomer($user);
            $this->fillGaps($user, $phone);
        } else {
            if ($phone !== null && User::where('phone', $phone)->exists()) {
                throw new DomainException('Этот телефон уже указан в аккаунте. Войдите прежним способом, чтобы подтвердить привязку.');
            }

            if ($email === null) {
                throw new DomainException('Провайдер не передал адрес почты, а без него нельзя выставить чек.');
            }

            $user = User::create([
                'name' => $profile->name ?: 'Покупатель',
                'email' => $email,
                'phone' => $this->freePhone($phone),
                // Пароля у такого пользователя нет вовсе, и админка ему
                // не положена: `is_admin` выставляется только руками.
                'password' => null,
                'is_admin' => false,
            ]);
        }

        $user->socialAccounts()->create([
            'provider' => $profile->provider,
            'provider_user_id' => $profile->id,
            'email' => $email,
            'phone' => $phone,
        ]);

        if ($isNew) {
            $this->businessNotifications->queue('user', $user->id, 'users', $profile->provider);
        }

        return $user;
    }

    private function matchingCustomer(?string $email, ?string $phone): ?User
    {
        if ($email === null && $phone === null) {
            return null;
        }

        $matches = User::query()->where(function (Builder $users) use ($email, $phone): void {
            if ($email !== null) {
                $users->whereRaw('LOWER(users.email) = ?', [$email]);
            }

            $users->orWhereHas('socialAccounts', function (Builder $accounts) use ($email, $phone): void {
                $accounts->where(function (Builder $contacts) use ($email, $phone): void {
                    if ($email !== null) {
                        $contacts->whereRaw('LOWER(email) = ?', [$email]);
                    }

                    if ($phone !== null) {
                        $contacts->orWhere('phone', $phone);
                    }
                });
            });
        })->lockForUpdate()->limit(2)->get();

        if ($matches->count() > 1) {
            throw new DomainException('Почта и телефон связаны с разными аккаунтами. Напишите нам — поможем объединить их.');
        }

        return $matches->first();
    }

    private function guardCustomer(User $user): void
    {
        if ($user->is_admin) {
            throw new DomainException('Этот адрес почты уже занят другим аккаунтом. Напишите нам — поможем войти.');
        }
    }

    private function email(?string $value): ?string
    {
        $email = mb_strtolower(trim($value ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Привязать гостевой заказ к аккаунту.
     *
     * Ровно то, что просил заказчик: «аккаунт предлагаем создать уже после
     * оформления, в один клик». Право на заказ доказано раньше — токеном
     * из ссылки, по которой человек его и открыл.
     */
    public function attachOrder(User $user, Order $order): void
    {
        if ($order->user_id === null) {
            $order->update(['user_id' => $user->id]);
        }
    }

    /**
     * Дозаполнить пустые поля при повторном входе.
     *
     * Заполненное не трогаем: человек мог поправить имя у нас, и затирать
     * его данными провайдера при каждом входе — значит отменять правку.
     */
    private function fillGaps(User $user, ?string $providerPhone): void
    {
        $fill = [];

        if (blank($user->phone) && filled($phone = $this->freePhone($providerPhone))) {
            $fill['phone'] = $phone;
        }

        if ($fill !== []) {
            $user->update($fill);
        }
    }

    /**
     * Телефон уникален — будущий вход по коду из СМС иначе не сойдётся.
     * При заполнении уже найденного аккаунта не перезаписываем чужой
     * контактный номер; для связывания используется social_accounts.phone.
     */
    private function freePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        return User::where('phone', $phone)->exists() ? null : $phone;
    }
}
