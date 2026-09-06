<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DeclarationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadRequestController;
use App\Http\Controllers\LegalPageController;
use App\Models\LegalPage;
use App\Http\Controllers\LineController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SitemapController;
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
    Route::get('/' . $slug, [LegalPageController::class, 'show'])
        ->defaults('slug', $slug)
        ->name('legal.' . $slug);
}

Route::get('/contacts', [PageController::class, 'contacts'])->name('contacts');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');