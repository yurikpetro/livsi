<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LineController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/product/{product}', [CatalogController::class, 'show'])->name('catalog.show');

Route::get('/pro', [LineController::class, 'pro'])->name('catalog.pro');
Route::get('/line/{line:code}', [LineController::class, 'show'])->name('catalog.line');

Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::post('/cart/gift', [CartController::class, 'chooseGift'])->name('cart.gift');

Route::get('/partners', [PageController::class, 'partners'])->name('partners');
Route::get('/contract-manufacturing', [PageController::class, 'contractManufacturing'])->name('contract');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');