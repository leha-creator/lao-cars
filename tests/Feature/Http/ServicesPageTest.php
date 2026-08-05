<?php

use App\Enums\ServiceCategory;
use App\Models\Lead;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Страница автосервиса (веха 4.4).
 *
 * Страница собирается из прайса и настроек, и каждый её блок обязан уметь
 * не отрендериться: форма настроек пишет свои ключи безусловно, даже пустыми,
 * а очищенный репитер приходит `null`, а не `[]`. Плюс сюда переехал
 * четвёртый сценарий формы заявки — выбор источника из списка, — и он обязан
 * ложиться на валидацию источника, не обходя её.
 *
 * Существующие `SectionPagesTest`, `LeadStoreTest`, `LayoutTest`
 * и `CatalogIndexTest` этой вехой НЕ правятся. Их покраснение — регресс,
 * а не устаревший тест: первые два описывают контракт вехи 3.7, третий —
 * состав меню, четвёртый — заголовок каталога слотом.
 */

/*
 * Счётчик лимитера заявок живёт в Redis и переживает RefreshDatabase.
 * Правило `RULES.md` требует сброса в каждом файле, который отправляет
 * форму, а не только в том, где проверяется сам лимит: иначе падать
 * начинает произвольный соседний тест, и связь с причиной не читается.
 */
beforeEach(function (): void {
    resetRateLimiters();
});

/**
 * Опубликованная позиция прайса в заданной категории.
 */
function pricePosition(ServiceCategory $category, string $title, ?int $price = 1000, ?string $note = null): Service
{
    return Service::factory()->inCategory($category)->create([
        'title' => $title,
        'price' => $price,
        'price_note' => $note,
        'description' => null,
    ]);
}

it('renders category blocks in the enum order and without parts', function () {
    foreach (ServiceCategory::cases() as $category) {
        pricePosition($category, "Позиция {$category->value}");
    }

    $response = $this->get('/services')->assertOk();

    // Порядок блоков задаёт енам, а не шаблон: второй список категорий
    // в Blade разошёлся бы с первым на первой же новой категории.
    $response->assertSeeInOrder(array_map(
        fn (ServiceCategory $category): string => $category->label(),
        ServiceCategory::serviceCategories(),
    ));

    // У запчастей своя посадочная страница, и блока здесь быть не должно —
    // ни заголовка категории, ни её позиции.
    $response->assertDontSee('Позиция parts')
        ->assertDontSee('href="#parts"', escape: false);
});

it('hides an unpublished position from both the price list and the form select', function () {
    pricePosition(ServiceCategory::Maintenance, 'Опубликованная работа');

    Service::factory()->maintenance()->unpublished()->create(['title' => 'Снятая с публикации работа']);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Опубликованная работа')
        // Проверка одна на два места: селект формы собирается из тех же
        // блоков, и позиция, выпавшая из прайса, обязана выпасть и из него.
        ->assertDontSee('Снятая с публикации работа');
});

it('drops a category without published positions from the blocks and the anchor nav', function () {
    // Две живые категории обязательны: при одном блоке навигации нет вовсе,
    // и тест прошёл бы вхолостую, ничего не проверив.
    pricePosition(ServiceCategory::Maintenance, 'Живая работа');
    pricePosition(ServiceCategory::Detailing, 'Вторая живая работа');

    Service::factory()->tireService()->unpublished()->create(['title' => 'Мёртвый шиномонтаж']);

    $response = $this->get('/services')->assertOk();

    // Проверять отсутствие ЯКОРЯ, а не только подписи: ссылка на
    // несуществующий якорь не работает молча, и подпись категории может
    // встретиться на странице по другому поводу.
    $response->assertSee('href="#maintenance"', escape: false)
        ->assertSee('href="#detailing"', escape: false)
        ->assertDontSee('href="#tire-service"', escape: false)
        ->assertDontSee('Мёртвый шиномонтаж');
});

it('drops the anchor nav entirely when a single category is left', function () {
    // Листать нечего: навигация из одной пилюли — это шум, ведущий
    // на середину того же экрана.
    pricePosition(ServiceCategory::Detailing, 'Единственная работа');

    $this->get('/services')
        ->assertOk()
        ->assertSee('Единственная работа')
        ->assertDontSee('href="#detailing"', escape: false);
});

it('formats the price by the position of the note', function () {
    pricePosition(ServiceCategory::Maintenance, 'Плановое ТО', 6500, 'от');
    pricePosition(ServiceCategory::TireService, 'Шиномонтаж колеса', 1200, 'за колесо');
    pricePosition(ServiceCategory::Detailing, 'Ремонт по запросу', null);

    $this->get('/services')
        ->assertOk()
        // Данные не различают префикс и суффикс: «от» стоит перед суммой,
        // «за колесо» — после, а колонка одна и заполняется свободным текстом.
        ->assertSee('от 6 500 ₽')
        ->assertSee('1 200 ₽ за колесо')
        ->assertSee('по запросу');
});

it('shows the category note from settings and keeps the block when it is emptied', function () {
    pricePosition(ServiceCategory::Maintenance, 'Работа с описанием');

    Setting::set('services_page.notes', [
        ServiceCategory::Maintenance->value => 'Проверочное описание категории.',
    ]);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Проверочное описание категории.');

    Setting::set('services_page.notes', [ServiceCategory::Maintenance->value => '']);
    Setting::flushCache();

    // Пустое описание убирает абзац, но НЕ блок: прайс важнее текста,
    // и категория без описания остаётся на странице.
    $this->get('/services')
        ->assertOk()
        ->assertSee('Работа с описанием')
        ->assertDontSee('Проверочное описание категории.');
});

it('drops the price disclaimer block when the setting is emptied', function () {
    pricePosition(ServiceCategory::Maintenance, 'Любая работа');

    Setting::set('services_page.price_disclaimer', 'Проверочная оговорка о ценах.');

    $this->get('/services')
        ->assertOk()
        ->assertSee('Проверочная оговорка о ценах.')
        ->assertSee('Не публичная оферта');

    Setting::set('services_page.price_disclaimer', null);
    Setting::flushCache();

    // Плашка исчезает вместе с текстом: плашка без пояснения ничего
    // не сообщает.
    $this->get('/services')
        ->assertOk()
        ->assertDontSee('Не публичная оферта');
});

it('drops the whole advantages section when the repeater is emptied', function () {
    Setting::set('services_page.advantages', [
        ['number' => '07', 'title' => 'Проверочное преимущество', 'text' => 'Текст преимущества.'],
    ]);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Проверочное преимущество')
        ->assertSee('Мастерская');

    // Репитер при удалении всех элементов отдаёт `null`, а не `[]`, —
    // именно этот случай ломает шаблон, написанный по сиду.
    Setting::set('services_page.advantages', null);
    Setting::flushCache();

    $this->get('/services')
        ->assertOk()
        ->assertDontSee('Проверочное преимущество')
        // Фотопанель уходит вместе с карточками: фотография без них рядом
        // читается как обрезанный блок.
        ->assertDontSee('Мастерская')
        ->assertDontSee('Почему сюда');
});

it('captures a lead with the service chosen in the select', function () {
    Queue::fake();

    $service = pricePosition(ServiceCategory::Detailing, 'Полировка кузова', 12000, 'от');

    $this->from('/services')
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
            'source_type' => 'service',
            'source_id' => (string) $service->getKey(),
        ])
        ->assertRedirect('/services')
        ->assertSessionHasNoErrors();

    // Именно эта связь даёт менеджеру «Услуга: …» в списке заявок
    // и в Telegram — то, чего свободный текст «Интересует» не даёт.
    expect(Lead::query()->sole()->sourceLabel())->toBe('Услуга: Полировка кузова');
});

it('captures a general lead when the visitor needs a consultation', function () {
    Queue::fake();

    pricePosition(ServiceCategory::Detailing, 'Полировка кузова');

    // Вариант «Нужна консультация» отправляет скрытый тип с пустым
    // `source_id`: скрытое поле в форме статично, выбирать нечего.
    // Отклонить такую заявку значило бы запретить посетителю НЕ выбирать
    // услугу — то есть сломать половину сценария формы.
    $this->from('/services')
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
            'source_type' => 'service',
            'source_id' => '',
        ])
        ->assertRedirect('/services')
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    // Тип без id в колонку не пишется: полузаполненный полиморфный
    // указатель врал бы про источник, и врал бы только в базе.
    expect($lead->source_type)->toBeNull()
        ->and($lead->source_id)->toBeNull()
        ->and($lead->sourceLabel())->toBe('Общая форма');
});

it('rejects a lead for a position taken off the site while the form was open', function () {
    Queue::fake();

    $service = Service::factory()->detailing()->unpublished()->create(['title' => 'Снятая позиция']);

    // Сторож того, что новый селект не обошёл валидацию вехи 3.7:
    // неопубликованной услуги нет ни на одной странице сайта.
    $this->from('/services')
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
            'source_type' => 'service',
            'source_id' => (string) $service->getKey(),
        ])
        ->assertSessionHasErrors('source_id');

    expect(Lead::query()->count())->toBe(0);
});

it('serves the page and warns when the price list is empty', function () {
    Log::spy();

    // Позиции засеяны для разработки, а на проде их заводит заказчик.
    // Пока он этого не сделал, страница состоит из заголовка и формы —
    // и снаружи выглядит работающей, поэтому диагностику закрывает лог.
    $this->get('/services')->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'нет ни одной опубликованной позиции прайса')
            && array_key_exists('hint', $context),
    )->once();
});

it('does not show the service select when there is nothing to choose', function () {
    // Выпадающий список из одного варианта «нужна консультация» ничего
    // не выбирает, а скрытый `source_type` рядом с ним отправлял бы тип
    // без всякого источника.
    $this->get('/services')
        ->assertOk()
        ->assertDontSee('id="lead-service"', escape: false)
        ->assertDontSee('name="source_type"', escape: false);
});

it('never leaks blade comment text into the markup', function (string $uri) {
    // Сторож против ловушки, которая уже сработала в этой вехе: парсер
    // блейдовских комментариев вложенность не понимает, и открывающая
    // или закрывающая последовательность, написанная ВНУТРИ комментария,
    // закрывает его раньше времени — остаток пояснения уезжает в разметку
    // страницы как есть. Все четыре страницы держатся на `x-page-heading`
    // с его длинным комментарием, поэтому проверка идёт по всем сразу.
    //
    // Ни один функциональный тест этого не поймал бы: разметка валидна,
    // ответ 200, нужные строки на месте — просто над заголовком страницы
    // висит абзац из исходника шаблона.
    $html = $this->get($uri)->assertOk()->getContent();

    expect($html)->not->toContain('{{--')
        ->and($html)->not->toContain('--}}');
})->with(['/services', '/parts', '/', '/contacts', '/catalog']);

it('keeps the query count independent of the number of categories', function () {
    // Шапка и подвал читают настройки на каждой странице, а кеш `Setting`
    // сбрасывается перед каждым тестом: без прогрева первый запрос платит
    // за промах кеша, и разница в единицу объявила бы рост запросов там,
    // где его нет.
    warmSettingsCache();

    pricePosition(ServiceCategory::Maintenance, 'Одна работа');

    $few = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Одна работа'));

    pricePosition(ServiceCategory::TireService, 'Вторая работа');
    pricePosition(ServiceCategory::Detailing, 'Третья работа');
    pricePosition(ServiceCategory::Extra, 'Четвёртая работа');

    $many = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Четвёртая работа'));

    // Запрос на категорию означал бы четыре запроса ради четырёх блоков
    // плюс пятый на селект формы. Нижняя граница обязательна — правило
    // `RULES.md`: выборка, не поймавшая ни одного запроса, иначе проходит
    // вхолостую.
    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});
