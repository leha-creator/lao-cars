<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HelpScreenshotController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PartsController;
use App\Http\Controllers\PushSubscriptionController;
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

// «О компании» (веха 4.5). Имя роута обязано быть `about.index` — оно уже
// объявлено в `SiteMenu::LABELS` и `FOOTER` с вехи 4.1, и любое другое
// означало бы, что пункт подвала так и не появится: `SiteMenu::links()`
// молча выбрасывает пункты без зарегистрированного роута. Порядок строк
// повторяет порядок пунктов меню, а не дату появления страницы.
Route::get('/about', [AboutController::class, 'index'])->name('about.index');

// Приём заявок со всех форм сайта (веха 3.7). Лимитер `leads` объявлен
// в `AppServiceProvider::configureRateLimiting()`.
Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('throttle:leads')
    ->name('leads.store');

/*
 * Подписка браузера сотрудника на push-уведомления (веха 4.7).
 *
 * Здесь, а не в провайдере панели Filament: это эндпоинт, а не страница,
 * и спрятанный в провайдере роут теряется при первом же поиске
 * по маршрутам.
 *
 * `throttle` обязателен: ручка пишет в базу по запросу из браузера,
 * а браузер перерегистрирует подписку сам и без спроса. Лимит общий
 * (`throttle:30,1`), а не именованный, как у заявок: тонкой настройки
 * тут не нужно — за ним стоит `auth`, то есть анонимного трафика нет.
 */
Route::middleware(['auth', 'throttle:30,1'])->group(function (): void {
    Route::post('/admin/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('push-subscriptions.store');

    Route::delete('/admin/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');
});

/*
 * Снимки экрана для статей справки (веха 4.15).
 *
 * Здесь, а не в провайдере панели, по тому же основанию, что и подписка
 * выше: это раздача файла, а не страница панели, и спрятанный
 * в провайдере роут теряется при первом же поиске по маршрутам.
 *
 * Отдельным объявлением, а НЕ внутри группы подписки: у той стоит
 * `throttle:30,1`. Там это верно — ручка пишет в базу по запросу
 * из браузера; здесь читается файл, а страница статьи запрашивает разом
 * столько картинок, сколько их в тексте. Общий лимит на статье с шестью
 * снимками сработал бы как отказ при пятом её открытии.
 *
 * Гость под `auth` уезжает на страницу входа панели, а не в
 * `RouteNotFoundException`: `redirectGuestsTo()` настроен
 * в `bootstrap/app.php` вехой 4.7.
 */
Route::get('/admin/help/image/{name}', HelpScreenshotController::class)
    ->middleware('auth')
    ->name('help.screenshot');
