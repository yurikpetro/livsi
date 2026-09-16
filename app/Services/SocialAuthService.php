<?php

namespace App\Services;

use App\Auth\SocialProfile;
use App\Models\Order;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Кого впустить по ответу внешнего провайдера.
 *
 * Здесь одно правило, ради которого класс и существует: **найденного
 * по почте пользователя мы не присваиваем**. Соблазн велик — «почта
 * совпала, значит тот же человек» — но провайдер ручается только за свой
 * идентификатор. Если завести у него аккаунт с чужим адресом, совпадение
 * по почте отдаст чужой кабинет; для админской почты это отдало бы
 * админку. Поэтому связь только по паре «провайдер + его идентификатор»,
 * а совпадение адреса — повод отказать и разобраться руками.
 *
 * Когда появится вход по коду из СМС, у нас будет свой подтверждённый
 * канал, и правило можно будет ослабить: сращивать по телефону, который
 * подтвердили мы сами.
 */
class SocialAuthService
{
    public function login(SocialProfile $profile): User
    {
        return DB::transaction(function () use ($profile) {
            $account = SocialAccount::where('provider', $profile->provider)
                ->where('provider_user_id', $profile->id)
                ->first();

            if ($account) {
                $user = $account->user;
                $this->fillGaps($user, $profile);

                return $user;
            }

            if (blank($profile->email)) {
                throw new RuntimeException('Провайдер не передал адрес почты, а без него нельзя выставить чек.');
            }

            if (User::where('email', $profile->email)->exists()) {
                throw new RuntimeException('Этот адрес почты уже занят другим аккаунтом. Напишите нам — поможем войти.');
            }

            $user = User::create([
                'name'  => $profile->name ?: 'Покупатель',
                'email' => $profile->email,
                'phone' => $this->freePhone($profile->phone),
                // Пароля у такого пользователя нет вовсе, и админка ему
                // не положена: `is_admin` выставляется только руками.
                'password' => null,
                'is_admin' => false,
            ]);

            $user->socialAccounts()->create([
                'provider'         => $profile->provider,
                'provider_user_id' => $profile->id,
                'email'            => $profile->email,
            ]);

            return $user;
        });
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
    private function fillGaps(User $user, SocialProfile $profile): void
    {
        $fill = [];

        if (blank($user->phone) && filled($phone = $this->freePhone($profile->phone))) {
            $fill['phone'] = $phone;
        }

        if ($fill !== []) {
            $user->update($fill);
        }
    }

    /**
     * Телефон уникален — будущий вход по коду из СМС иначе не сойдётся.
     * Занятый чужим аккаунтом просто не записываем: разбираться с этим
     * при входе через кнопку неуместно, а падать тем более.
     */
    private function freePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        return User::where('phone', $phone)->exists() ? null : $phone;
    }
}
