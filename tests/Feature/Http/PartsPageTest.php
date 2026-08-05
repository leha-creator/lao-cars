<?php

use App\Models\Service;
use Illuminate\Support\Facades\Log;

/*
 * Посадочная запчастей (веха 4.4).
 *
 * Страница показывает категории запчастей карточками и НЕ проставляет источник
 * заявки. Оба решения выглядят как недоделка для того, кто открыл файл после
 * страницы автосервиса, — поэтому на оба стоит сторож.
 *
 * Файл не отправляет форму заявки, поэтому `resetRateLimiters()` ему не нужен.
 * Если здесь появится `post(route('leads.store'))`, `beforeEach` с ним
 * становится обязательным — правило `RULES.md`.
 */

it('lists published parts categories in sort order', function () {
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Третья категория', 'sort_order' => 30]);
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Первая категория', 'sort_order' => 10]);
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Вторая категория', 'sort_order' => 20]);

    $this->get('/parts')
        ->assertOk()
        ->assertSeeInOrder(['Первая категория', 'Вторая категория', 'Третья категория']);
});

it('hides an unpublished parts category', function () {
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Живая категория']);
    Service::factory()->parts()->unpublished()->create(['title' => 'Снятая категория']);

    $this->get('/parts')
        ->assertOk()
        ->assertSee('Живая категория')
        ->assertDontSee('Снятая категория');
});

it('shows a price only when it is filled and never falls back to «по запросу»', function () {
    Service::factory()->parts()->create(['title' => 'Категория с ценой', 'price' => 4500, 'price_note' => 'от']);
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Категория без цены']);

    // Сторож решения: у всех засеянных категорий цена пустая, и пять
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

it('serves the page without the section and warns when the parts category is empty', function () {
    Log::spy();

    // Позиции засеяны для разработки, а на проде их заводит заказчик:
    // посадочная без категорий выглядит недоделанной при живом ответе 200.
    $this->get('/parts')
        ->assertOk()
        ->assertDontSee('Что подбираем');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'нет ни одной опубликованной позиции')
            && array_key_exists('hint', $context),
    )->once();
});

it('keeps the parts landing and the services page from leaking into each other', function () {
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Кузовные детали']);
    Service::factory()->maintenance()->create(['title' => 'Плановое ТО']);

    $this->get('/parts')
        ->assertOk()
        ->assertSee('Кузовные детали')
        ->assertDontSee('Плановое ТО');

    // Обратная сторона той же развилки: категорию запчастей исключает сам
    // енам (`isParts()`), а не условие в цикле шаблона.
    //
    // Проверять здесь отсутствие подписи `ServiceCategory::Parts->label()`
    // нельзя: «Запчасти» — пункт меню, и он есть в шапке и подвале каждой
    // страницы. Сторож блока — его якорь.
    $this->get('/services')
        ->assertOk()
        ->assertSee('Плановое ТО')
        ->assertDontSee('Кузовные детали')
        ->assertDontSee('href="#parts"', escape: false);
});

it('does not set a lead source on the parts form', function () {
    Service::factory()->parts()->withoutPrice()->create(['title' => 'Расходники для ТО']);

    // Сторож решения: заявку на подбор отличают поля автомобиля клиента
    // (`Lead::isPartsRequest()`), а категория без артикула менеджеру
    // не добавляет ничего — он всё равно идёт к поставщику по VIN.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('name="part_vin"', escape: false)
        ->assertDontSee('name="source_id"', escape: false)
        ->assertDontSee('name="source_type"', escape: false);
});
