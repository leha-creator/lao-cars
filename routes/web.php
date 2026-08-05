<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Каталог автомобилей (веха 3.6). Роут карточки объявляется после роута
// списка: `/catalog/{car}` не должен перехватывать `/catalog`.
Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{car}', [CatalogController::class, 'show'])->name('catalog.show');
