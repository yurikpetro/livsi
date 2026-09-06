<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Администратор Back Office.
 *
 * Заводится сидером, а не руками: иначе после `migrate:fresh --seed`
 * в админку не войти, и это уже случалось.
 *
 * Пароль по умолчанию есть только для локальной машины. На боевом контуре
 * без ADMIN_EMAIL и ADMIN_PASSWORD в окружении администратор не создаётся —
 * общеизвестный пароль в продакшене хуже, чем его отсутствие.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('livsi.admin.email');
        $password = config('livsi.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn(
                'Администратор не создан: задайте ADMIN_EMAIL и ADMIN_PASSWORD в окружении.'
            );

            return;
        }

        $user = User::withoutEvents(fn () => User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => config('livsi.admin.name'),
                'password'          => $password,
                'is_admin'          => true,
                'email_verified_at' => now(),
            ],
        ));

        $this->command?->info("Администратор: {$user->email}");
    }
}
