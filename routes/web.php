<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/product/{product}', [CatalogController::class, 'show'])->name('catalog.show');

Route::get('/partners', [PageController::class, 'partners'])->name('partners');
Route::get('/contract-manufacturing', [PageController::class, 'contractManufacturing'])->name('contract');