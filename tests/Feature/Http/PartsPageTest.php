<?php

use App\Enums\ServicePage;
use App\Models\Media;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/*
 * Посадочная запчастей (веха 4.4, переработана вехой 4.13).
 *
 * Страница показывает позиции карточками и НЕ проставляет источник заявки.
 * Оба решения выглядят как недоделка для того, кто открыл файл после
 * страницы автосервиса, — поэтому на оба стоит сторож.
 *
 * ПРИНАДЛЕЖНОСТЬ СТРАНИЦЕ ЗАДАЁТ КОЛОНКА `page`, а не имя категории.
 * До вехи 4.13 отбор шёл по кейсу `ServiceCategory::Parts`, то есть
 * по константе языка: переименованная или удалённая из админки категория
 * опустошила бы эту страницу молча — 200, вступление, форма и ни одного
 * блока. Самый ценный сторож файла — `keeps the parts landing and the
 * services page from leaking into each other`: он и проверяет, что работает
 * флаг, а не имя.
 *
 * Категории заводятся фабрикой со своими слагами: миграция вехи 4.13
 * заводит пять исходных на любой базе, включая тестовую, и их имена
 * со слагами заняты. Категория «Запчасти» среди них — со страницей
 * `Parts` и без позиций, поэтому в разметку она не попадает, но тесту
 * про «на странице нет ни одной категории» мешает, и он сносит справочник
 * явно.
 *
 * Файл не отправляет форму заявки, поэтому `resetRateLimiters()` ему не нужен.
 * Если здесь появится `post(route('leads.store'))`, `beforeEach` с ним
 * становится обязательным — правило `RULES.md`.
 */

/**
 * Категория страницы запчастей.
 */
function partsCategory(string $name = 'Проверочные запчасти', string $slug = 'test-parts'): ServiceCategory
{
    return ServiceCategory::factory()->parts()->create([
        'name' => $name,
        'slug' => $slug,
        'description' => null,
    ]);
}

it('lists published parts positions in sort order', function () {
    $category = partsCategory();

    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Третья категория', 'sort_order' => 30]);
    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Первая категория', 'sort_order' => 10]);
    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Вторая категория', 'sort_order' => 20]);

    $this->get('/parts')
        ->assertOk()
        ->assertSeeInOrder(['Первая категория', 'Вторая категория', 'Третья категория']);
});

it('shows positions with a photo above the rest', function () {
    // Порядок выдачи задаёт `Service::ordered()`, то есть SQL, а не шаблон:
    // на посадочной запчастей карточки с фотографией и без неё стоят
    // в ОДНОЙ сетке, и разъехаться они могут только порядком.
    //
    // `sort_order` у позиции с фотографией выше, чем у позиции без неё:
    // если бы группы не работали, наверх встала бы вторая.
    $category = partsCategory();

    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Позиция без кадра', 'sort_order' => 10]);
    Service::factory()->inCategory($category)->withoutPrice()->withPhoto()->create(['title' => 'Позиция с кадром', 'sort_order' => 20]);

    $this->get('/parts')
        ->assertOk()
        ->assertSeeInOrder(['Позиция с кадром', 'Позиция без кадра']);
});

it('hides an unpublished parts position', function () {
    $category = partsCategory();

    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Живая категория']);
    Service::factory()->inCategory($category)->unpublished()->create(['title' => 'Снятая категория']);

    $this->get('/parts')
        ->assertOk()
        ->assertSee('Живая категория')
        ->assertDontSee('Снятая категория');
});

it('shows a price only when it is filled and never falls back to «по запросу»', function () {
    $category = partsCategory();

    Service::factory()->inCategory($category)->create(['title' => 'Категория с ценой', 'price' => 4500, 'price_note' => 'от']);
    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Категория без цены']);

    // Сторож решения: у всех засеянных позиций цена пустая, и пять
    // одинаковых подписей «по запросу» в столбик — шум, а не информация.
    // На странице автосервиса фолбэк несёт смысл (позиция в прайсе, где
    // у соседей суммы есть), здесь он повторил бы вводный текст пять раз.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('Категория с ценой')
        ->assertSee('от 4 500 ₽')
        ->assertSee('Категория без цены')
        ->assertDontSee('по запросу');
});

it('serves the page without the section and warns when there are no published positions', function () {
    Log::spy();

    // Категория есть, позиций нет: позиции засеяны для разработки,
    // а на проде их заводит заказчик. Посадочная без них выглядит
    // недоделанной при живом ответе 200.
    partsCategory();

    $this->get('/parts')
        ->assertOk()
        ->assertDontSee('Что подбираем');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'нет ни одной опубликованной позиции')
            && array_key_exists('hint', $context),
    )->once();
});

it('warns separately when the page has no categories at all', function () {
    Log::spy();

    // Два РАЗНЫХ отказа с разными починками: «заведите категорию
    // со страницей „Запчасти“» и «опубликуйте позиции». Одно сообщение
    // на оба отправило бы администратора публиковать позиции в категории,
    // которой нет.
    //
    // Справочник сносится явно: пять категорий заводит миграция вехи 4.13
    // на любой базе, и «Запчасти» среди них.
    Service::query()->delete();
    ServiceCategory::query()->delete();

    $this->get('/parts')
        ->assertOk()
        ->assertDontSee('Что подбираем');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'нет ни одной категории')
            && array_key_exists('hint', $context),
    )->once();
});

it('keeps the parts landing and the services page from leaking into each other', function () {
    $parts = partsCategory();

    $services = ServiceCategory::factory()->create([
        'name' => 'Проверочные работы',
        'slug' => 'test-maintenance',
        'page' => ServicePage::Services,
        'description' => null,
    ]);

    Service::factory()->inCategory($parts)->withoutPrice()->create(['title' => 'Кузовные детали']);
    Service::factory()->inCategory($services)->create(['title' => 'Плановое ТО']);

    $this->get('/parts')
        ->assertOk()
        ->assertSee('Кузовные детали')
        ->assertDontSee('Плановое ТО');

    // Обратная сторона той же развилки: категорию запчастей исключает
    // КОЛОНКА `page`, а не имя категории и не условие в цикле шаблона.
    //
    // Проверять здесь отсутствие подписи «Запчасти» нельзя: это пункт меню,
    // и он есть в шапке и подвале каждой страницы. Сторож блока — его якорь.
    $this->get('/services')
        ->assertOk()
        ->assertSee('Плановое ТО')
        ->assertDontSee('Кузовные детали')
        ->assertDontSee('href="#test-parts"', escape: false);
});

it('keeps the query count independent of the number of positions with a photo', function () {
    // Забытый `with('media')` даёт N+1 ровно там, где позиций больше всего,
    // и не роняет ни одного теста, кроме этого.
    warmSettingsCache();

    $category = partsCategory();

    Service::factory()->inCategory($category)->withoutPrice()->withPhoto()->create(['title' => 'Первая с кадром']);

    $one = countQueries(fn () => $this->get('/parts')->assertOk()->assertSee('Первая с кадром'));

    Media::factory()->count(2)->create()->each(
        fn (Media $media, int $index) => Service::factory()
            ->inCategory($category)
            ->withoutPrice()
            ->withPhoto($media)
            ->create(['title' => "Ещё одна с кадром {$index}"]),
    );

    $many = countQueries(fn () => $this->get('/parts')->assertOk()->assertSee('Ещё одна с кадром 1'));

    // Нижняя граница обязательна — правило `RULES.md`: выборка,
    // не поймавшая ни одного запроса, иначе проходит вхолостую.
    expect($one)->toBeGreaterThan(0)
        ->and($many)->toBe($one);
});

it('falls back to a default heading when the setting is emptied', function () {
    // Фолбэк через второй аргумент `Setting::get()` срабатывает только
    // на отсутствующий ключ, а форма настроек пишет пустое значение как есть.
    // Без нормализации очищенный заголовок давал бы пустой H1 и заголовок
    // документа из одного тире — при живой настройке и без ошибок в логе.
    Setting::set('parts_page.intro_title', '');

    $html = $this->get('/parts')->assertOk()->getContent();

    expect($html)->toContain('>Запчасти</h1>')
        ->and($html)->not->toContain('<title> — ');
});

it('does not set a lead source on the parts form', function () {
    $category = partsCategory();

    Service::factory()->inCategory($category)->withoutPrice()->create(['title' => 'Расходники для ТО']);

    // Сторож решения: заявку на подбор отличают поля автомобиля клиента
    // (`Lead::isPartsRequest()`), а категория без артикула менеджеру
    // не добавляет ничего — он всё равно идёт к поставщику по VIN.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('name="part_vin"', escape: false)
        ->assertDontSee('name="source_id"', escape: false)
        ->assertDontSee('name="source_type"', escape: false);
});
