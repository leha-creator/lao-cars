<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Свои страницы ошибок (веха 4.11).
 *
 * До этой вехи их не было вовсе: на 404 и 500 отдавалась штатная страница
 * Laravel — светлая, без шапки, без логотипа и без единой ссылки обратно.
 * Проверяются они здесь настоящим запросом, а не рендером шаблона: обе
 * страницы отдаёт обработчик исключений, и «шаблон существует» ничего
 * не говорит о том, что его кто-то показывает.
 */

it('renders the custom 404 page with the site layout', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('Такой страницы')
        ->assertSee('В каталог автомобилей')
        // Шапка и подвал на месте: 404 приходит от РАБОТАЮЩЕГО приложения,
        // и уводить с неё некуда, если меню нет.
        ->assertSee('Автомобили')
        ->assertSee('Запчасти');
});

it('keeps the 404 page out of the index and without a canonical url', function () {
    $response = $this->get('/no-such-page')->assertNotFound();

    $response->assertSee('name="robots" content="noindex,follow"', false);

    // Канонический адрес layout печатает по умолчанию, и на 404 это была бы
    // ссылка на несуществующую страницу как на образцовую версию себя.
    $response->assertDontSee('rel="canonical"', false);
});

/*
 * Пятисотка.
 *
 * Отдаётся только при выключенной отладке: с включённой на её месте стоит
 * разбор исключения, и это правильно — разработчику нужен стек.
 */
describe('500 page', function () {
    beforeEach(function () {
        config(['app.debug' => false]);

        Route::get('/__test-throws', function () {
            throw new RuntimeException('намеренная ошибка для проверки страницы 500');
        })->middleware('web');
    });

    it('renders the custom 500 page for an unhandled exception', function () {
        $this->get('/__test-throws')
            ->assertStatus(500)
            ->assertSee('Что-то пошло')
            ->assertSee('На главную');
    });

    /*
     * Главное про эту страницу: она не ходит в базу.
     *
     * Шапка и подвал сайта читают настройки (телефон, контакты, соцсети,
     * гарантия), то есть идут в `Setting`. Пятисотку отдают ровно тогда,
     * когда что-то уже сломано, и «упала база» — самая частая причина;
     * общий layout превратил бы одну ошибку в две, а исключение внутри
     * шаблона ошибки перехватывать уже нечем.
     *
     * Ноль запросов здесь — не тот случай, о котором предупреждает
     * `RULES.md` («счётчик 0 означает, что фильтр ничего не поймал»):
     * ноль и есть проверяемое утверждение, а не побочный результат.
     * Сторож на то, что счётчик вообще работает, — соседний тест
     * с непустым ожиданием.
     */
    it('renders the 500 page without touching the database', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/__test-throws')->assertStatus(500);

        expect($queries)->toBe([]);
    });

    it('counts queries at all — the layout pages do hit the database', function () {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/')->assertOk();

        expect($queries)->toBeGreaterThan(0);
    });
});
