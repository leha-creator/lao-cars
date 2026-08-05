<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\LeadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Каталог автомобилей (веха 3.6). Роут карточки объявляется после роута
// списка: `/catalog/{car}` не должен перехватывать `/catalog`.
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{car}', [CatalogController::class, 'show'])->name('catalog.show');

// Приём заявок со всех форм сайта (веха 3.7). Лимитер `leads` объявлен
// в `AppServiceProvider::configureRateLimiting()`.
Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('throttle:leads')
    ->name('leads.store');
