<?php

use App\Enums\ServicePage;
use App\Models\Lead;
use App\Models\Media;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Страница автосервиса (веха 4.4, переработана вехой 4.13).
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
 *
 * КАТЕГОРИИ ЗАВОДЯТСЯ ФАБРИКОЙ СО СВОИМИ СЛАГАМИ, а не берутся готовыми.
 * Миграция вехи 4.13 заводит пять исходных категорий (`maintenance`,
 * `tire-service`, `detailing`, `extra`, `parts`) на ЛЮБОЙ базе, включая
 * тестовую: перенос данных без ветвления «если прод» — её условие. Позиций
 * у этих пяти в тестах нет, поэтому в блоки страницы они не попадают,
 * но имя и слаг у них заняты — отсюда `test-` в слагах ниже.
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
 * Категория страницы автосервиса с предсказуемым слагом и порядком.
 */
function serviceBlock(string $name, string $slug, int $sortOrder = 0, ?string $description = null): ServiceCategory
{
    return ServiceCategory::factory()->create([
        'name' => $name,
        'slug' => $slug,
        'page' => ServicePage::Services,
        'sort_order' => $sortOrder,
        'description' => $description,
    ]);
}

/**
 * Разметка акцентной карточки — от её тега до ближайшего закрытия.
 *
 * Резать страницу обязательно: сторожа вехи 4.13 утверждают про карточку то,
 * что для остальной страницы неверно, — отсутствие `text-accent` при живом
 * `text-accent` в надзаголовке, ценах прайса и кнопке «Подробнее».
 * Прецедент — `processSectionHtml()` в `HomePageTest`.
 *
 * Карточка ищется по метке `data-featured`, а не по классу раскладки:
 * класс меняется при первой же правке вёрстки, и тест начал бы краснеть
 * на исправной странице. Вложенных `<a>` внутри карточки нет (кнопка
 * «Подробнее» лежит ЗА ней — интерактивный элемент внутри ссылки даёт
 * невалидную разметку), поэтому ближайшее закрытие и есть конец карточки.
 */
function featuredCardHtml(string $html): string
{
    $marker = strpos($html, 'data-featured');

    expect($marker)->not->toBeFalse('акцентная карточка не найдена по метке `data-featured`');

    $start = strrpos(substr($html, 0, (int) $marker), '<a');

    expect($start)->not->toBeFalse('у метки `data-featured` не найден открывающий тег ссылки');

    $end = strpos($html, '</a>', (int) $start);

    expect($end)->not->toBeFalse('у акцентной карточки не найдено закрытие');

    return substr($html, (int) $start, (int) $end + 4 - (int) $start);
}

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

it('renders category blocks in the directory order and without parts pages', function () {
    // Порядок задаёт `sort_order` справочника, а не порядок заведения
    // и не алфавит, — поэтому вторая по алфавиту категория создана первой
    // и получила нулевой порядок. До вехи 4.13 порядок задавал порядок
    // кейсов енама; источник сменился, проверяемое свойство — нет.
    $second = serviceBlock('Автомойка проверочная', 'test-detailing', 1);
    $first = serviceBlock('Яработы проверочные', 'test-maintenance', 0);

    $parts = ServiceCategory::factory()->parts()->create([
        'name' => 'Запчасти проверочные',
        'slug' => 'test-parts',
    ]);

    pricePosition($first, 'Позиция первой категории');
    pricePosition($second, 'Позиция второй категории');
    pricePosition($parts, 'Позиция запчастей');

    $response = $this->get('/services')->assertOk();

    $response->assertSeeInOrder([$first->name, $second->name]);

    // У страницы запчастей своя посадочная, и блока здесь быть не должно —
    // ни заголовка категории, ни её позиции. Отбор идёт по колонке `page`,
    // а не по имени категории: до вехи 4.13 он шёл по кейсу
    // `ServiceCategory::Parts`, то есть по константе языка.
    $response->assertDontSee('Позиция запчастей')
        ->assertDontSee('href="#test-parts"', escape: false);
});

it('hides an unpublished position from both the price list and the form select', function () {
    $category = serviceBlock('Проверочные работы', 'test-maintenance');

    pricePosition($category, 'Опубликованная работа');

    Service::factory()->inCategory($category)->unpublished()->create(['title' => 'Снятая с публикации работа']);

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
    $alive = serviceBlock('Живая категория', 'test-maintenance', 0);
    $second = serviceBlock('Вторая живая категория', 'test-detailing', 1);
    $dead = serviceBlock('Мёртвая категория', 'test-tire-service', 2);

    pricePosition($alive, 'Живая работа');
    pricePosition($second, 'Вторая живая работа');

    Service::factory()->inCategory($dead)->unpublished()->create(['title' => 'Мёртвый шиномонтаж']);

    $response = $this->get('/services')->assertOk();

    // Проверять отсутствие ЯКОРЯ, а не только подписи: ссылка на
    // несуществующий якорь не работает молча, и подпись категории может
    // встретиться на странице по другому поводу.
    $response->assertSee('href="#test-maintenance"', escape: false)
        ->assertSee('href="#test-detailing"', escape: false)
        ->assertDontSee('href="#test-tire-service"', escape: false)
        ->assertDontSee('Мёртвый шиномонтаж');
});

it('drops the anchor nav entirely when a single category is left', function () {
    // Листать нечего: навигация из одной пилюли — это шум, ведущий
    // на середину того же экрана.
    $category = serviceBlock('Единственная категория', 'test-detailing');

    pricePosition($category, 'Единственная работа');

    $this->get('/services')
        ->assertOk()
        ->assertSee('Единственная работа')
        ->assertDontSee('href="#test-detailing"', escape: false);
});

it('formats the price by the position of the note', function () {
    $first = serviceBlock('Первая категория', 'test-maintenance', 0);
    $second = serviceBlock('Вторая категория', 'test-tire-service', 1);
    $third = serviceBlock('Третья категория', 'test-detailing', 2);

    pricePosition($first, 'Плановое ТО', 6500, 'от');
    pricePosition($second, 'Шиномонтаж колеса', 1200, 'за колесо');
    pricePosition($third, 'Ремонт по запросу', null);

    $this->get('/services')
        ->assertOk()
        // Данные не различают префикс и суффикс: «от» стоит перед суммой,
        // «за колесо» — после, а колонка одна и заполняется свободным текстом.
        ->assertSee('от 6 500 ₽')
        ->assertSee('1 200 ₽ за колесо')
        ->assertSee('по запросу');
});

it('shows the category note from the directory and keeps the block when it is emptied', function () {
    // Описание приехало из колонки справочника, а не из настройки
    // `services_page.notes`: ключами той были значения енама, и с
    // редактируемым справочником такой объект превращается в мусор при
    // первом же удалении категории. Проверяемое свойство прежнее —
    // пустое описание убирает абзац, но НЕ блок.
    $category = serviceBlock('Проверочная категория', 'test-maintenance', 0, 'Проверочное описание категории.');

    pricePosition($category, 'Работа с описанием');

    $this->get('/services')
        ->assertOk()
        ->assertSee('Проверочное описание категории.');

    $category->update(['description' => '']);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Работа с описанием')
        ->assertDontSee('Проверочное описание категории.');
});

it('drops the price disclaimer block when the setting is emptied', function () {
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    pricePosition($category, 'Любая работа');

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

    $category = serviceBlock('Проверочный детейлинг', 'test-detailing');

    $service = pricePosition($category, 'Полировка кузова', 12000, 'от');

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

    $category = serviceBlock('Проверочный детейлинг', 'test-detailing');

    pricePosition($category, 'Полировка кузова');

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

it('keeps the chosen service selected after a validation error', function () {
    // Правило вехи 3.7 «old() во всех полях без исключения» распространяется
    // и на селект: после ошибки валидации посетитель не должен заново искать
    // свою позицию в списке из семнадцати.
    //
    // Это же сторож против `x-model` на селекте. Порчу в браузере тест
    // разметки не поймает — Alpine затирает значение уже после ответа
    // сервера, — но он ловит обратное: исчезновение серверного `@selected`,
    // без которого затирать будет нечего и симптом станет постоянным.
    $category = serviceBlock('Проверочный детейлинг', 'test-detailing');

    $service = pricePosition($category, 'Полировка кузова', 12000, 'от');

    // `followingRedirects()`, а не отдельный `get()` после `post()`: флеш
    // ошибок к следующему запросу теста уже состарен, и проверка «форма
    // вернулась с сохранённым выбором» прошла бы вхолостую. Здесь редирект
    // отрисовывается в том же цикле — ровно то, что видит посетитель.
    $this->from('/services')
        ->followingRedirects()
        ->post(route('leads.store'), [
            'name' => 'Иван',
            // Телефон не заполнен — форма вернётся с ошибкой.
            'source_type' => 'service',
            'source_id' => (string) $service->getKey(),
        ])
        ->assertOk()
        ->assertSee('value="'.$service->getKey().'" selected', escape: false);
});

it('shows the source error next to the select, not only above the button', function () {
    // Формулировка вехи 3.7 рассчитана на подделку скрытого поля, а получит
    // её живой человек, чью услугу сняли с публикации, пока он заполнял
    // форму. Сообщение обязано указывать на поле, а не висеть над кнопкой.
    $detailing = serviceBlock('Проверочный детейлинг', 'test-detailing', 0);
    $maintenance = serviceBlock('Проверочные работы', 'test-maintenance', 1);

    $service = Service::factory()->inCategory($detailing)->unpublished()->create(['title' => 'Снятая позиция']);

    pricePosition($maintenance, 'Живая работа');

    $html = $this->from('/services')
        ->followingRedirects()
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
            'source_type' => 'service',
            'source_id' => (string) $service->getKey(),
        ])
        ->assertOk()
        ->getContent();

    $select = strpos($html, 'id="lead-service"');
    $error = strpos($html, 'Заявка отправлена на несуществующий объект.');
    $nextField = strpos($html, 'name="contact_method"');

    expect($select)->not->toBeFalse()
        ->and($error)->not->toBeFalse()
        // Сообщение стоит МЕЖДУ селектом и следующим полем формы.
        ->and($error)->toBeGreaterThan($select)
        ->and($error)->toBeLessThan($nextField)
        // Копии над кнопкой быть не должно: одна ошибка — одно сообщение.
        ->and(substr_count($html, 'Заявка отправлена на несуществующий объект.'))->toBe(1);
});

it('rejects a lead for a position taken off the site while the form was open', function () {
    Queue::fake();

    $category = serviceBlock('Проверочный детейлинг', 'test-detailing');

    $service = Service::factory()->inCategory($category)->unpublished()->create(['title' => 'Снятая позиция']);

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

it('warns separately when the services page has no categories at all', function () {
    Log::spy();

    // Позиции есть и опубликованы — просто ни одна категория не выведена
    // на страницу услуг. До вехи 4.13 такого состояния не существовало:
    // категорий было ровно пять и убрать их было нельзя. Теперь можно,
    // переключив всем поле «Страница», и страница отдаёт 200 с заголовком
    // и формой, но без единого блока. Найдено на приёмке в браузере.
    $parts = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);

    ServiceCategory::query()->whereKeyNot($parts->getKey())->update(['page' => ServicePage::Parts]);

    Service::factory()->inCategory($parts)->create(['title' => 'Позиция запчастей']);

    $this->get('/services')
        ->assertOk()
        ->assertDontSee('Позиция запчастей');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, '[Автосервис] на странице нет ни одной категории')
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

    pricePosition(serviceBlock('Первая', 'test-maintenance', 0), 'Одна работа');

    $few = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Одна работа'));

    pricePosition(serviceBlock('Вторая', 'test-tire-service', 1), 'Вторая работа');
    pricePosition(serviceBlock('Третья', 'test-detailing', 2), 'Третья работа');
    pricePosition(serviceBlock('Четвёртая', 'test-extra', 3), 'Четвёртая работа');

    $many = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Четвёртая работа'));

    // Запрос на категорию означал бы четыре запроса ради четырёх блоков
    // плюс пятый на селект формы. Веха 4.13 добавила к постоянной части
    // выборку справочника и предзагрузку связей — все три константы,
    // от числа категорий не зависящие, поэтому сравнение осталось
    // сравнением, а не сверкой с числом.
    //
    // Нижняя граница обязательна — правило `RULES.md`: выборка,
    // не поймавшая ни одного запроса, иначе проходит вхолостую.
    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('renders the three groups of a category block in order: featured, with photo, plain', function () {
    // Порядок групп — прямая формулировка заказчика («сначала показывались
    // пункты с фото, а потом остальные») и он же решает вопрос гармонии:
    // смешивать карточку с кадром и строку прайса в одном потоке нечем.
    //
    // Утверждение по ВЗАИМНОМУ ПОЛОЖЕНИЮ подстрок, а не по наличию каждой:
    // наличие проходит при любом порядке, то есть не проверяет ничего.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    // Заводятся в обратном порядке: если бы выдача шла по времени создания
    // или по `sort_order` без групп, тест прошёл бы вхолостую.
    pricePosition($category, 'Обычная строка прайса');

    Service::factory()->inCategory($category)->withPhoto()->create(['title' => 'Позиция с фотографией']);
    Service::factory()->inCategory($category)->featured()->create(['title' => 'Акцентная позиция']);

    $html = $this->get('/services')->assertOk()->getContent();

    expect($html)->toContain('Акцентная позиция');

    $this->get('/services')
        ->assertSeeInOrder(['Акцентная позиция', 'Позиция с фотографией', 'Обычная строка прайса']);

    // Порядка подстрок МАЛО: если бы группы не разделялись вовсе и все три
    // позиции рисовались строками прайса, порядок остался бы тем же —
    // `Service::ordered()` сортирует так же. Поэтому проверяется ещё
    // и ВИД карточки: акцентная позиция лежит внутри широкой карточки,
    // а соседи по блоку — нет.
    $card = featuredCardHtml($html);

    expect($card)->toContain('Акцентная позиция')
        ->and($card)->not->toContain('Позиция с фотографией')
        ->and($card)->not->toContain('Обычная строка прайса');

    // Позиция с фотографией рисуется карточкой с кадром, а строка прайса —
    // нет: `<img` с её названием в `alt` есть, с названием строки прайса
    // нет вовсе.
    expect($html)->toContain('alt="Позиция с фотографией"')
        ->and($html)->not->toContain('alt="Обычная строка прайса"');
});

it('keeps sort_order authoritative inside a group but never across groups', function () {
    // `sort_order` авторитетен ВНУТРИ группы, а не поверх неё: перетаскивание
    // строки без фотографии выше строки с фотографией на сайте не изменит
    // ничего, и об этом прямо написано в подсказке поля в админке. Строка
    // прайса здесь получает САМЫЙ низкий порядок — без групп она встала бы
    // первой.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->create(['title' => 'Строка с нулевым порядком', 'sort_order' => 0]);
    Service::factory()->inCategory($category)->withPhoto()->create(['title' => 'Вторая с фотографией', 'sort_order' => 20]);
    Service::factory()->inCategory($category)->withPhoto()->create(['title' => 'Первая с фотографией', 'sort_order' => 10]);

    $this->get('/services')
        ->assertOk()
        ->assertSeeInOrder(['Первая с фотографией', 'Вторая с фотографией', 'Строка с нулевым порядком']);
});

it('paints the featured card without accent text over the photo', function () {
    // Ловушка палитры: `text-accent` — неизменяемый жёлтый, дающий 1.4:1
    // на светлом кадре, и `docs/design-system.md` запрещает его поверх
    // фотографии прямым решением вехи 4.11. Акцентный ЦВЕТ приходит
    // на кадр единственным допустимым способом — сплошной заливкой плашки
    // цены, где контраст обеспечен внутри пары `accent-solid`/`on-accent`.
    //
    // Проверка ЧЕРЕЗ ОТРИЦАНИЕ обязательна (прецедент вехи 4.12):
    // положительная прошла бы и при забытом `text-accent` рядом.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->featured()->withPhoto()->create([
        'title' => 'Акцентная позиция',
        'price' => 35000,
        'price_note' => 'от',
    ]);

    $card = featuredCardHtml($this->get('/services')->assertOk()->getContent());

    expect($card)->toContain('from-scrim/85')
        ->and($card)->toContain('text-on-photo')
        ->and($card)->toContain('bg-accent-solid')
        // Описание с ценой лежит ПОД кадром ниже `lg`, на светлом фоне
        // страницы, и чернила поверх фотографии там невидимы. Базовые
        // чернила темы обязаны быть, а `on-photo` приходить с брейкпойнтом.
        ->and($card)->toContain('text-ink-muted')
        ->and($card)->toContain('lg:text-on-photo/75')
        // Якорь регулярного выражения сторожится отдельно, и `\b` для него
        // НЕ ГОДИТСЯ: дефис — не словесный символ, поэтому `/\btext-accent\b/`
        // совпадает и с `text-accent-solid`, и с `text-accent-hover`. Тест
        // краснел бы на разрешённой плашке, и «починили» бы его снятием
        // проверки. Отрицательный просмотр вперёд запрещает продолжение
        // класса и разрешает его конец.
        ->and($card)->not->toMatch('/text-accent(?![\w-])/');
});

it('anchors the accent guard so it does not fire on the allowed accent fill', function () {
    // Прямой сторож самого сторожа: выражение выше обязано пропускать
    // `text-accent-solid` и `text-accent-hover` и ловить голый
    // `text-accent`. Проверять это на живой странице нельзя — она
    // разрешённых классов сейчас не содержит, и ошибка в якоре осталась бы
    // невидимой до первой правки вёрстки.
    $pattern = '/text-accent(?![\w-])/';

    expect('class="bg-accent-solid text-on-accent"')->not->toMatch($pattern)
        ->and('class="text-accent-solid"')->not->toMatch($pattern)
        ->and('class="text-accent-hover"')->not->toMatch($pattern)
        ->and('class="text-accent"')->toMatch($pattern)
        ->and('class="mt-3 text-accent transition"')->toMatch($pattern);
});

it('paints a featured position without a photo on the surface, not on the scrim', function () {
    // Флаг и фотография — разные поля, и акцентная позиция без кадра
    // штатное состояние: администратор ставит флаг раньше, чем получает
    // снимок. Пара `scrim`/`on-photo` при этом НЕ применяется — белым
    // чернилам не на чем работать, и на светлой карточке они исчезли бы.
    // Заметить это можно было бы только глазами.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->featured()->create([
        'title' => 'Акцентная позиция без кадра',
        'description' => 'Описание акцентной позиции.',
    ]);

    $card = featuredCardHtml($this->get('/services')->assertOk()->getContent());

    expect($card)->toContain('Акцентная позиция без кадра')
        ->and($card)->toContain('bg-surface')
        ->and($card)->not->toContain('text-on-photo')
        ->and($card)->not->toContain('from-scrim/85');
});

it('prints the details text into the markup and hides the toggle when it is empty', function () {
    // Условие деградации без скрипта: `x-show` скрывает элемент присвоением
    // `display:none` из JavaScript, то есть при неработающем Alpine текст
    // обязан остаться ВИДЕН, а не потеряться. Значит он обязан быть
    // напечатан сервером — это и проверяется.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->withPhoto()->create([
        'title' => 'Позиция с подробностями',
        'details' => 'Подробное описание услуги, напечатанное сервером.',
    ]);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Подробное описание услуги, напечатанное сервером.')
        ->assertSee('Подробнее');
});

it('shows no details toggle for a position without details', function () {
    // Кнопка, открывающая пустоту, читается как поломка.
    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->withPhoto()->create([
        'title' => 'Позиция без подробностей',
        'details' => null,
    ]);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Позиция без подробностей')
        ->assertDontSee('Подробнее');
});

it('keeps the query count independent of the number of positions with a photo', function () {
    // Ловушка названа в плане вехи заранее: забытый `with('media')` даёт
    // N+1 ровно там, где позиций больше всего, и не роняет ни одного теста,
    // кроме этого.
    warmSettingsCache();

    $category = serviceBlock('Проверочная категория', 'test-maintenance');

    Service::factory()->inCategory($category)->withPhoto()->create(['title' => 'Первая с фотографией']);

    $one = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Первая с фотографией'));

    Media::factory()->count(2)->create()->each(
        fn (Media $media, int $index) => Service::factory()
            ->inCategory($category)
            ->withPhoto($media)
            ->create(['title' => "Ещё одна с фотографией {$index}"]),
    );

    $many = countQueries(fn () => $this->get('/services')->assertOk()->assertSee('Ещё одна с фотографией 1'));

    // Нижняя граница обязательна — правило `RULES.md`.
    expect($one)->toBeGreaterThan(0)
        ->and($many)->toBe($one);
});
