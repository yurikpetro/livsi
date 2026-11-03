<?php

use App\Http\Controllers\Livsipro\CustomersController;
use App\Http\Controllers\Livsipro\OrdersController;
use App\Http\Middleware\LivsiproToken;
use Illuminate\Support\Facades\Route;

/*
| Заказы сайта для вкладки «Сайт» на livsipro.ru.
|
| Только по токену и только с адреса livsipro (LivsiproToken). Сессий и
| кук здесь нет: это разговор двух серверов, а не браузера.
*/
Route::prefix('livsipro')->middleware([LivsiproToken::class, 'throttle:livsipro'])->group(function (): void {
    Route::get('/orders', [OrdersController::class, 'index']);
    Route::get('/orders/{number}', [OrdersController::class, 'show']);
    Route::post('/orders/{number}/marks', [OrdersController::class, 'addMark']);
    Route::delete('/orders/{number}/marks/{mark}', [OrdersController::class, 'removeMark'])->whereNumber('mark');
    Route::post('/orders/{number}/assembled', [OrdersController::class, 'assembled']);
    Route::post('/orders/{number}/shipment', [OrdersController::class, 'shipment']);
    Route::get('/orders/{number}/label', [OrdersController::class, 'label']);
    Route::post('/orders/{number}/shipped', [OrdersController::class, 'shipped']);
    Route::post('/orders/{number}/delivered', [OrdersController::class, 'delivered']);
    Route::post('/orders/{number}/reassemble', [OrdersController::class, 'reassemble']);
    Route::post('/orders/{number}/cancel-shipment', [OrdersController::class, 'cancelShipment']);
    Route::post('/orders/{number}/restock', [OrdersController::class, 'restock']);
    Route::post('/orders/{number}/refresh', [OrdersController::class, 'refresh']);

    // Клиенты, промокоды, рассылки — на livsipro только для администраторов.
    Route::get('/customers', [CustomersController::class, 'index']);
    Route::get('/customers/{id}', [CustomersController::class, 'show'])->whereNumber('id');
    Route::post('/customers/promo', [CustomersController::class, 'issuePromo']);
    Route::get('/promo-codes', [CustomersController::class, 'promoCodes']);
    Route::get('/mailings', [CustomersController::class, 'mailingsIndex']);
    Route::get('/mailings/{id}', [CustomersController::class, 'mailingShow'])->whereNumber('id');
    Route::post('/mailings', [CustomersController::class, 'mailingStore']);
    Route::post('/mailings/test', [CustomersController::class, 'mailingTest']);
});
