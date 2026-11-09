<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\VkAuthController;
use App\Http\Controllers\Auth\YandexAuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CityGeocodeController;
use App\Http\Controllers\DeclarationController;
use App\Http\Controllers\DeliveryPointsController;
use App\Http\Controllers\DeliveryQuoteController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadRequestController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderReturnController;
use App\Http\Controllers\OzonClientCheckController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TochkaWebhookController;
use App\Http\Controllers\PrintOrderController;
use App\Http\Controllers\ReturnPhotoController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Middleware\CaptureUtm;
use App\Models\LegalPage;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/product/{product}', [CatalogController::class, 'show'])->name('catalog.show');

Route::get('/pro', [LineController::class, 'pro'])->name('catalog.pro');
Route::get('/line/{line:code}', [LineController::class, 'show'])->name('catalog.line');

Route::get('/declarations', DeclarationController::class)->name('declarations');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::post('/cart/gift', [CartController::class, 'chooseGift'])->name('cart.gift');

Route::get('/partners', [PageController::class, 'partners'])->name('partners');

Route::post('/partners', [LeadRequestController::class, 'storeWholesale'])
    ->middleware('throttle:10,60')
    ->name('partners.store');

Route::get('/contract-manufacturing', [PageController::class, 'contractManufacturing'])->name('contract');

// Ограничение частоты вместо капчи: капча требует согласия на передачу данных
// третьей стороне и решения заказчика, а спам ловится и приманкой в форме.
Route::post('/contract-manufacturing', [LeadRequestController::class, 'storeContract'])
    ->middleware('throttle:10,60')
    ->name('contract.store');

// Юридические и информационные документы. Адреса объявлены статически:
// на них ссылаются чекбоксы согласий, чеки, письма и уведомление в РКН,
// поэтому меняться из админки они не должны.
foreach (array_keys(LegalPage::SLUGS) as $slug) {
    // Замыкание здесь недопустимо: с ним перестаёт работать route:cache.
    Route::get('/'.$slug, [LegalPageController::class, 'show'])
        ->defaults('slug', $slug)
        ->name('legal.'.$slug);
}

Route::get('/contacts', [PageController::class, 'contacts'])->name('contacts');

// Оформление заказа и оплата.
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/delivery/quote', DeliveryQuoteController::class)
    ->middleware('throttle:delivery-quotes')
    ->name('delivery.quote');
Route::post('/delivery/ozon/check-client', OzonClientCheckController::class)
    ->middleware('throttle:ozon-clients')
    ->name('delivery.ozon.check-client');
Route::get('/delivery/points', DeliveryPointsController::class)
    ->middleware('throttle:delivery-points')
    ->name('delivery.points');
Route::post('/delivery/city/geocode', CityGeocodeController::class)
    ->middleware('throttle:delivery-geocode')
    ->name('delivery.city.geocode');
// Промокод: кнопка «Применить» отправляет форму оформления сюда и возвращает обратно.
Route::post('/checkout/promo', [CheckoutController::class, 'applyPromo'])->middleware('throttle:20,1')->name('checkout.promo');
Route::post('/checkout/promo/remove', [CheckoutController::class, 'removePromo'])->name('checkout.promo.remove');
Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware('throttle:20,60')
    ->name('checkout.store');

// Отписка от рассылки по подписанной ссылке из письма.
// POST — «отписаться в один клик» из почтовой программы (List-Unsubscribe-Post): без формы и без CSRF.
Route::match(['get', 'post'], '/unsubscribe/{customer}', UnsubscribeController::class)
    ->middleware('signed')->withoutMiddleware([PreventRequestForgery::class])->name('unsubscribe');

// Заказ открывается по номеру и токену из письма: кабинета у гостя нет.
Route::get('/order/{order}', [OrderController::class, 'show'])->name('order.show');

// Возврат после получения: владелец аккаунта или ссылка с токеном из письма.
Route::get('/order/{order}/return', [OrderReturnController::class, 'create'])->name('order.return');
Route::post('/order/{order}/return', [OrderReturnController::class, 'store'])
    ->middleware('throttle:10,60')
    ->name('order.return.store');
Route::post('/order/{order}/return/cancel', [OrderReturnController::class, 'cancel'])
    ->middleware('throttle:20,60')
    ->name('order.return.cancel');

// Внутренний лист для комплектации заказа: проверка прав — в контроллере.
Route::get('/admin/orders/{order}/print', PrintOrderController::class)->name('admin.orders.print');
Route::get('/admin/returns/{return}/photos/{index}', ReturnPhotoController::class)->whereNumber('index')->name('admin.returns.photo');

// Вебхук провайдера: без сессии и без проверки CSRF — запрос приходит
// не из браузера. Защита — список адресов и перепроверка статуса по API.
Route::post('/webhooks/yookassa', PaymentWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        CaptureUtm::class,
    ])
    ->name('webhooks.yookassa');

// Уведомления Точки: тело — JWT, статус всё равно перепроверяется по API.
Route::post('/webhooks/tochka', TochkaWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        CaptureUtm::class,
    ])
    ->middleware('throttle:120,1')
    ->name('webhooks.tochka');

// Избранное. Гостю доступно так же, как корзина: сердечко — не то место,
// где уместно требовать вход.
Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites');
Route::post('/favorites/{product}', [FavoriteController::class, 'toggle'])
    ->middleware('throttle:60,1')
    ->name('favorites.toggle');

// Личный кабинет. Гостевой заказ он не отменяет: страница заказа
// по-прежнему открывается по токену без входа.
Route::get('/login', [AccountController::class, 'login'])
    ->middleware('guest')
    ->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');

    Route::post('/account/orders/{order}/repeat', [AccountController::class, 'repeat'])
        ->name('account.repeat');
});

// Вход через Яндекс ID. Начало — POST: кнопка стоит на странице заказа
// и передаёт его токен, а такой запрос не должен уходить по чужой ссылке.
Route::post('/auth/yandex', [YandexAuthController::class, 'redirect'])
    ->middleware('throttle:20,60')
    ->name('auth.yandex');

// Адрес возврата зарегистрирован у провайдера посимвольно — менять нельзя.
Route::get('/auth/yandex/callback', [YandexAuthController::class, 'callback'])
    ->name('auth.yandex.callback');

Route::post('/auth/vk', [VkAuthController::class, 'redirect'])
    ->defaults('provider', 'vk')
    ->middleware(['guest', 'throttle:20,60'])
    ->name('auth.vk');

Route::post('/auth/mail', [VkAuthController::class, 'redirect'])
    ->defaults('provider', 'mail')
    ->middleware(['guest', 'throttle:20,60'])
    ->name('auth.mail');

Route::get('/auth/vk/callback', [VkAuthController::class, 'callback'])
    ->middleware('guest')
    ->name('auth.vk.callback');

Route::post('/logout', [YandexAuthController::class, 'logout'])->name('logout');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
