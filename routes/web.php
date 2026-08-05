<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PartsController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Каталог автомобилей (веха 3.6). Роут карточки объявляется после роута
// списка: `/catalog/{car}` не должен перехватывать `/catalog`.
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{car}', [CatalogController::class, 'show'])->name('catalog.show');

// Разделы закреплённого меню (веха 4.1). Страницы пока заглушки на каркасе:
// наполнение приходит вехами 4.4 (автосервис, запчасти) и 4.5 (контакты).
// Заведены сейчас, потому что без адресов три из четырёх пунктов меню вели
// бы в никуда, а `SiteMenu` выводит только пункты с зарегистрированным роутом.
Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
Route::get('/parts', [PartsController::class, 'index'])->name('parts.index');
Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');

// Приём заявок со всех форм сайта (веха 3.7). Лимитер `leads` объявлен
// в `AppServiceProvider::configureRateLimiting()`.
Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('throttle:leads')
    ->name('leads.store');
