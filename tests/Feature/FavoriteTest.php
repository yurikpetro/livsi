<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use App\Services\FavoriteService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Избранное.
 *
 * Главное здесь — гостевой сценарий: сердечко доступно без входа, а при входе
 * отложенное переезжает в аккаунт. И граница: чужой список не виден и не правится.
 */
class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function product(int $skip = 0): Product
    {
        return Product::where('is_active', true)->orderBy('id')->skip($skip)->firstOrFail();
    }

    private function customer(): User
    {
        return User::create([
            'name'  => 'Иван',
            'email' => 'ivan+' . Str::random(6) . '@example.com',
        ]);
    }

    // ─────────────────────────────────── гость

    public function test_guest_can_favorite_without_logging_in(): void
    {
        $product = $this->product();

        $response = $this->post(route('favorites.toggle', $product));

        $response->assertRedirect();

        $this->assertSame(1, Favorite::count());
        $this->assertNull(Favorite::sole()->user_id, 'Гостевая запись не должна быть привязана к пользователю');
        $this->assertNotNull(Favorite::sole()->token);
    }

    public function test_toggle_removes_what_was_added(): void
    {
        $product = $this->product();

        $first = $this->post(route('favorites.toggle', $product));
        $token = $first->getCookie(FavoriteService::COOKIE, false)?->getValue();

        $this->assertNotNull($token, 'Гостю должна выдаваться cookie списка');

        $this->withUnencryptedCookie(FavoriteService::COOKIE, $token)
            ->post(route('favorites.toggle', $product));

        $this->assertSame(0, Favorite::count());
    }

    /**
     * Два сердечка подряд — один список.
     *
     * Cookie уезжает только с ответом, поэтому в том же запросе её ещё нет
     * в `request()`. Без подстановки значения второе нажатие завело бы
     * второй токен и второй список.
     */
    public function test_two_hearts_in_one_request_cycle_share_the_list(): void
    {
        $response = $this->post(route('favorites.toggle', $this->product()));
        $token = $response->getCookie(FavoriteService::COOKIE, false)?->getValue();

        $this->withUnencryptedCookie(FavoriteService::COOKIE, $token)
            ->post(route('favorites.toggle', $this->product(1)));

        $this->assertSame(1, Favorite::distinct()->count('token'), 'Список раздвоился');
        $this->assertSame(2, Favorite::count());
    }

    // ─────────────────────────────────── пользователь

    public function test_logged_in_favorites_belong_to_the_account(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->post(route('favorites.toggle', $this->product()));

        $this->assertSame($user->id, Favorite::sole()->user_id);
        $this->assertNull(Favorite::sole()->token);
    }

    public function test_page_shows_only_your_own_favorites(): void
    {
        $mine   = $this->product();
        $theirs = $this->product(1);

        $me      = $this->customer();
        $someone = $this->customer();

        Favorite::create(['user_id' => $me->id, 'product_id' => $mine->id]);
        Favorite::create(['user_id' => $someone->id, 'product_id' => $theirs->id]);

        $this->actingAs($me)
            ->get(route('favorites'))
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    public function test_guest_sees_an_empty_state(): void
    {
        $this->get(route('favorites'))
            ->assertOk()
            ->assertSee('Пока пусто');
    }

    // ─────────────────────────────────── перенос при входе

    public function test_guest_list_moves_into_the_account_on_login(): void
    {
        $product = $this->product();
        $token   = Str::random(48);

        Favorite::create(['token' => $token, 'product_id' => $product->id]);

        $user = $this->customer();

        $this->withUnencryptedCookie(FavoriteService::COOKIE, $token)
            ->actingAs($user);

        // Перенос выполняется сервисом, как и при возврате от провайдера.
        $this->withUnencryptedCookie(FavoriteService::COOKIE, $token)
            ->get(route('favorites'));

        app()->call(function (FavoriteService $favorites) use ($user, $token) {
            request()->cookies->set(FavoriteService::COOKIE, $token);
            $favorites->mergeInto($user);
        });

        $this->assertSame($user->id, Favorite::sole()->user_id);
        $this->assertNull(Favorite::sole()->token);
    }

    /**
     * Товар, отмеченный и гостем, и пользователем, не должен ронять вход.
     *
     * Составной уникальный ключ не даст сохранить дубль, а падать на входе
     * из-за сердечка неуместно.
     */
    public function test_duplicate_on_merge_does_not_break_login(): void
    {
        $product = $this->product();
        $token   = Str::random(48);
        $user    = $this->customer();

        Favorite::create(['user_id' => $user->id, 'product_id' => $product->id]);
        Favorite::create(['token' => $token, 'product_id' => $product->id]);

        app()->call(function (FavoriteService $favorites) use ($user, $token) {
            request()->cookies->set(FavoriteService::COOKIE, $token);
            $favorites->mergeInto($user);
        });

        $this->assertSame(1, Favorite::count());
        $this->assertSame($user->id, Favorite::sole()->user_id);
    }

    // ─────────────────────────────────── витрина

    public function test_card_marks_what_is_already_favorited(): void
    {
        $user    = $this->customer();
        $product = $this->product();

        Favorite::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $this->actingAs($user)
            ->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('product-favorite liked', escape: false);
    }

    public function test_header_shows_the_favorites_link(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Избранное');
    }

    /** Снятый с продажи товар не показывается в списке, но и не удаляется. */
    public function test_inactive_product_drops_out_of_the_list(): void
    {
        $user    = $this->customer();
        $product = $this->product();

        Favorite::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $product->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('favorites'))
            ->assertOk()
            ->assertDontSee($product->title);

        $this->assertSame(1, Favorite::count(), 'Запись стирать не нужно: товар может вернуться');
    }
}
