<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Избранное гостя и пользователя.
 *
 * Устроено по образцу корзины: пока человек не вошёл, владельцем записи
 * служит токен из cookie. Гостевой сценарий на сайте обязателен, и сердечко
 * — последнее место, где уместно требовать регистрацию: человек только что
 * проявил интерес, а мы в ответ показываем форму входа.
 *
 * При входе гостевые записи переезжают в аккаунт, иначе они выглядели бы
 * как потерянные: только что были — и нет.
 */
class FavoriteService
{
    public const COOKIE = 'livsi_favorites';

    private const COOKIE_DAYS = 90;

    /** @var array<int>|null Мемоизация на запрос: список читается на каждой карточке. */
    private ?array $ids = null;

    private ?string $boundTo = null;

    /**
     * Переключить и вернуть новое состояние: true — теперь в избранном.
     */
    public function toggle(Product $product): bool
    {
        $owner = $this->owner();

        $existing = $this->scope()?->where('product_id', $product->id)->first();

        if ($existing) {
            $existing->delete();
            $this->forget();

            return false;
        }

        Favorite::create($owner + ['product_id' => $product->id]);
        $this->forget();

        return true;
    }

    /** @return array<int> */
    public function ids(): array
    {
        $scope = $this->scope();

        if ($scope === null) {
            return [];
        }

        $key = (string) (auth()->id() ?? $this->token());

        // Мемоизация привязана к владельцу, а не ко времени жизни объекта:
        // в тестах и под Octane один экземпляр переживает несколько запросов.
        if ($this->boundTo === $key && $this->ids !== null) {
            return $this->ids;
        }

        $this->boundTo = $key;

        // Сначала недавнее: список читается сверху вниз.
        return $this->ids = $scope->orderByDesc('id')->pluck('product_id')->all();
    }

    public function has(Product $product): bool
    {
        return in_array($product->id, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** @return Collection<int, Product> */
    public function products(): Collection
    {
        $ids = $this->ids();

        if ($ids === []) {
            return new Collection;
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['line', 'images', 'variants.quota'])
            ->get()
            // Порядок задаёт избранное, а не база.
            ->sortBy(fn (Product $p) => array_search($p->id, $ids, true))
            ->values();
    }

    /**
     * Перенести гостевое избранное в аккаунт при входе.
     *
     * Товары, которые уже отмечены у пользователя, просто удаляются
     * из гостевого списка: составной уникальный ключ иначе не даст
     * сохранить, а падать на входе из-за сердечка неуместно.
     */
    public function mergeInto(User $user): void
    {
        $token = $this->token();

        if ($token === null) {
            return;
        }

        DB::transaction(function () use ($user, $token) {
            $mine = Favorite::where('user_id', $user->id)->pluck('product_id')->all();

            Favorite::where('token', $token)
                ->whereIn('product_id', $mine)
                ->delete();

            Favorite::where('token', $token)
                ->update(['user_id' => $user->id, 'token' => null]);
        });

        $this->forget();
    }

    /**
     * Запрос по владельцу записей.
     *
     * Через построитель, а не через массив условий: `user_id => null`
     * превратилось бы в `user_id = null`, что в SQL не совпадает ни с чем,
     * и гостевое избранное молча стало бы пустым.
     */
    private function scope(): ?Builder
    {
        if ($id = auth()->id()) {
            return Favorite::query()->where('user_id', $id);
        }

        $token = $this->token();

        return $token === null
            ? null
            : Favorite::query()->where('token', $token)->whereNull('user_id');
    }

    /**
     * Чем подписать новую запись. Гостю здесь же заводится токен.
     *
     * @return array<string, mixed>
     */
    private function owner(): array
    {
        if ($id = auth()->id()) {
            return ['user_id' => $id];
        }

        $token = $this->token();

        if ($token === null) {
            $token = Str::random(48);

            // Cookie ставится в очередь и уедет с ответом; в этом же запросе
            // её ещё нет в request(), поэтому подкладываем значение сами —
            // иначе два сердечка подряд завели бы два разных списка.
            Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_DAYS * 24 * 60));
            request()->cookies->set(self::COOKIE, $token);
        }

        return ['token' => $token];
    }

    private function forget(): void
    {
        $this->ids = null;
        $this->boundTo = null;
    }

    private function token(): ?string
    {
        $token = request()->cookie(self::COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
