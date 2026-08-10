<?php

use App\Enums\CarStatus;
use App\Enums\ServiceCategory;
use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;

/*
 * Главная (вехи 4.2 и 4.6).
 *
 * Страница собирается из настроек, каталога, прайса и отзывов, и каждый её
 * блок обязан уметь не отрендериться: форма настроек пишет свои ключи
 * безусловно, даже пустыми, а очищенный репитер приходит `null`, а не `[]`.
 * Шаблон, написанный по сиду, падает на первом же `foreach (null)` — и падает
 * на проде, а не здесь, если тесты тоже написаны по сиду.
 *
 * Значения настроек задаются В ТЕСТЕ, а не берутся из сида: проверка сида
 * показала бы, что работает `SiteSettingSeeder`, а не что страница читает
 * настройки.
 *
 * `LayoutTest`, `LeadStoreTest` и `SectionPagesTest` этой вехой НЕ правятся.
 * Их покраснение — регресс, а не устаревший тест: первый описывает состав
 * меню, остальные два — контракт формы заявки вехи 3.7.
 */

/*
 * Счётчик лимитера заявок живёт в Redis и переживает `RefreshDatabase`.
 * Вехой 4.6 в этот файл приехал тест, отправляющий форму (селект услуги
 * и `old()`), а правило `RULES.md` требует сброса в КАЖДОМ файле, который
 * форму отправляет, а не только в том, где проверяется сам лимит: иначе
 * падать начинает произвольный соседний тест, и связь с причиной
 * не читается.
 */
beforeEach(function (): void {
    resetRateLimiters();
});

/**
 * Сколько карточек авто на странице.
 *
 * Считается по ссылкам НА КАРТОЧКУ: адрес карточки — это адрес списка плюс
 * слэш и slug, поэтому префикс `href="…/catalog/` есть только у них.
 *
 * Раньше здесь стоял голый `substr_count($html, '/catalog/')`, и вехой 4.6
 * он стал хрупким: на странице появились ещё три ссылки на каталог — кнопка
 * хиро, кнопка быстрого подбора и `x-bind:href` Alpine. Ни одна из них
 * завершающего слэша не даёт, то есть счётчик уцелел бы случайно.
 * Считать по `href` с префиксом списка — то же число, но по построению,
 * а не по совпадению.
 *
 * Заголовки для счёта не годятся: `h3` теперь у половины блоков страницы.
 */
function carCardCount(string $html): int
{
    return substr_count($html, 'href="'.route('catalog.index').'/');
}

it('renders ticker, promo and advantages from site settings', function () {
    // Значения меняются в тесте намеренно: проверка сида показала бы, что
    // работает SiteSettingSeeder, а не что страница читает настройки.
    Setting::set('home.ticker', ['Первый тезис ленты', 'Второй тезис ленты']);
    Setting::set('home.promo', [
        'title' => 'Проверочный промо-заголовок',
        'text' => 'Проверочный текст промо-блока.',
        'link_text' => 'Проверочная кнопка',
        'link_url' => '#lead-form',
        'image_id' => null,
    ]);
    Setting::set('home.advantages', [
        ['number' => '09', 'title' => 'Проверочное преимущество', 'text' => 'Текст преимущества.'],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Первый тезис ленты')
        ->assertSee('Второй тезис ленты')
        ->assertSee('Проверочный промо-заголовок')
        ->assertSee('Проверочный текст промо-блока.')
        ->assertSee('Проверочная кнопка')
        ->assertSee('Проверочное преимущество')
        ->assertSee('Текст преимущества.');
});

it('shows only cars flagged for the homepage and still available', function () {
    $flagged = Car::factory()->onHomepage()->create(['model' => 'Отмеченный']);
    $plain = Car::factory()->create(['model' => 'Неотмеченный']);
    // Проданный с отметкой — тупик: карточка открывается, статус говорит
    // «продан», покупать нечего. Пересечение делает сервис, а не скоуп:
    // смысл `onHomepage()` — «отмечен администратором», и в этом значении
    // его читают CarTest и SeedersTest.
    $sold = Car::factory()->sold()->onHomepage()->create(['model' => 'Проданный']);

    $this->get('/')
        ->assertOk()
        ->assertSee($flagged->model)
        ->assertDontSee($plain->model)
        ->assertDontSee($sold->model);
});

it('orders the homepage selection by sort_order', function () {
    Car::factory()->onHomepage()->create(['model' => 'Третий', 'sort_order' => 30]);
    Car::factory()->onHomepage()->create(['model' => 'Первый', 'sort_order' => 10]);
    Car::factory()->onHomepage()->create(['model' => 'Второй', 'sort_order' => 20]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['Первый', 'Второй', 'Третий']);
});

it('caps the homepage selection at the configured limit', function () {
    // Лимит берётся из конфига, а не числом: иначе тест падает от смены
    // умолчания, хотя ничего не сломано.
    $limit = (int) config('catalog.homepage_limit');

    Car::factory()->onHomepage()->count($limit + 1)->create();

    $html = $this->get('/')->assertOk()->getContent();

    expect(carCardCount($html))->toBe($limit);
});

it('does not add a query per car in the homepage selection', function () {
    // Прогрев обязателен: кеш настроек сбрасывается перед каждым тестом,
    // и промах на первом запросе добавил бы ему один запрос — разницу
    // в единицу объявили бы N+1 там, где его нет.
    warmSettingsCache();

    Car::factory()->onHomepage()->count(2)->create()
        ->each(fn (Car $car) => CarPhoto::factory()->for($car)->create());

    $few = countQueries(fn () => $this->get('/')->assertOk()->assertSee('Не бесконечный каталог'));

    Car::factory()->onHomepage()->count(4)->create()
        ->each(fn (Car $car) => CarPhoto::factory()->for($car)->create());

    $many = countQueries(fn () => $this->get('/')->assertOk()->assertSee('Не бесконечный каталог'));

    // Нижняя граница обязательна — правило `RULES.md`: выборка, не поймавшая
    // ни одного запроса, иначе проходит вхолостую.
    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('drops the selection section entirely when nothing is flagged', function () {
    Car::factory()->count(3)->create();

    // Проверка по заголовку секции, а не по классу: класс переживёт правку
    // вёрстки, заголовок и есть содержание. Ссылка «Весь каталог →» уходит
    // вместе с секцией — заголовок над пустой сеткой и ссылка в никуда
    // читаются как поломка. Вместе с ними уходит и карточка-приглашение:
    // «Не нашли модель?» над пустой сеткой обещает подбор из ничего.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Не бесконечный каталог')
        ->assertDontSee('Не нашли модель?')
        ->assertDontSee('Весь каталог');
});

it('drops the ticker when the setting is emptied', function () {
    // Репитер настроек при удалении всех элементов отдаёт `null`, а не `[]`.
    Setting::set('home.ticker', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Более 8 лет на рынке импорта авто');
});

it('drops the promo banner when title and text are cleared', function () {
    // Форма настроек вехи 3.5 пишет ключ безусловно, даже пустым:
    // «очистить блок» там рабочий сценарий.
    Setting::set('home.promo', [
        'title' => '',
        'text' => '',
        'link_text' => 'Кнопка осиротевшего промо',
        'link_url' => '#lead-form',
        'image_id' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Кнопка осиротевшего промо');
});

it('drops the advantages section when the list is emptied', function () {
    Setting::set('home.advantages', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Почему мы');
});

it('renders the promo button only when it has both a label and a url', function () {
    // Кнопка с пустым `href` уводит на текущую страницу и при этом выглядит
    // рабочей — та же ошибка, от которой подвал защищён тестом вехи 4.1.
    Setting::set('home.promo', [
        'title' => 'Промо без кнопки',
        'text' => 'Текст промо.',
        'link_text' => '',
        'link_url' => '#lead-form',
        'image_id' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Промо без кнопки')
        ->assertDontSee('href=""', escape: false);
});

it('makes the header transparent on the home page only', function () {
    // Отличие ищется по атрибуту липкости, а не по цвету: цвет переживёт
    // правку вёрстки, а `sticky` и есть то, чем состояния различаются.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('sticky top-0');

    $this->get('/catalog')
        ->assertOk()
        ->assertSee('sticky top-0');
});

it('takes the page title and description from site settings', function () {
    Setting::set('seo.default_title', 'Проверочный заголовок сайта');
    Setting::set('seo.default_description', 'Проверочное описание сайта.');

    $this->get('/')
        ->assertOk()
        ->assertSee('<title>Проверочный заголовок сайта</title>', escape: false)
        ->assertSee('content="Проверочное описание сайта."', escape: false);
});

it('falls back to the template title when the seo settings are empty', function () {
    Setting::set('seo.default_title', '');
    Setting::set('seo.default_description', '');

    // Пустая настройка обязана дать строку из шаблона, а не пустой тег:
    // сайт остаётся с осмысленным заголовком.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('<title></title>', escape: false)
        ->assertSee(config('app.name').' — импорт автомобилей и автосервис');
});

it('renders steps, price breakdown and faq from site settings', function () {
    Setting::set('home.steps', [
        ['number' => '07', 'title' => 'Проверочный этап', 'text' => 'Текст проверочного этапа.'],
    ]);
    Setting::set('home.price_breakdown', [
        ['title' => 'Проверочная статья расходов', 'note' => 'проверочное уточнение'],
    ]);
    Setting::set('home.faq', [
        ['question' => 'Проверочный вопрос?', 'answer' => 'Проверочный ответ.'],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Проверочный этап')
        ->assertSee('Текст проверочного этапа.')
        ->assertSee('Проверочная статья расходов')
        ->assertSee('проверочное уточнение')
        ->assertSee('Проверочный вопрос?')
        ->assertSee('Проверочный ответ.');
});

it('drops the steps section when the list is emptied', function () {
    // Репитер настроек при удалении всех элементов отдаёт `null`, а не `[]`.
    // Проверка по заголовку секции, а не по классу: класс переживёт правку
    // вёрстки, заголовок и есть содержание.
    Setting::set('home.steps', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Каждый этап понятен');
});

it('drops the price breakdown section together with its note', function () {
    Setting::set('home.price_breakdown', null);

    // Нота уходит вместе со списком: без состава цены она ничего
    // не сообщает — объясняет, что будет с тем, чего на странице нет.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Клиент видит, из чего складывается')
        ->assertDontSee('Получить расчёт');
});

it('drops the faq section when the list is emptied', function () {
    Setting::set('home.faq', null);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Закрываем вопросы');
});

it('skips a faq item without an answer', function () {
    // `<summary>` без содержимого раскрывается в пустоту, и это читается
    // как сломанный аккордеон, а не как отсутствующий ответ.
    Setting::set('home.faq', [
        ['question' => 'Вопрос с ответом?', 'answer' => 'Ответ на месте.'],
        ['question' => 'Вопрос без ответа?', 'answer' => ''],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Вопрос с ответом?')
        ->assertDontSee('Вопрос без ответа?');
});

it('shows only published reviews and keeps the configured limit', function () {
    // Лимит берётся из конфига, а не числом: иначе тест падает от смены
    // умолчания, хотя ничего не сломано.
    $limit = (int) config('home.reviews_limit');

    foreach (range(1, $limit) as $position) {
        Review::factory()->published()->create([
            'sort_order' => $position,
            'body' => "Опубликованный отзыв {$position}",
        ]);
    }

    Review::factory()->published()->create([
        'sort_order' => $limit + 1,
        'body' => 'Отзыв сверх лимита',
    ]);

    Review::factory()->pending()->create(['body' => 'Отзыв на модерации']);

    $response = $this->get('/')->assertOk();

    foreach (range(1, $limit) as $position) {
        $response->assertSee("Опубликованный отзыв {$position}");
    }

    $response->assertDontSee('Отзыв сверх лимита')
        // Немодерированный отзыв на сайте — дефект, и путь к нему закрыт
        // скоупом в сервисе, а не условием в шаблоне.
        ->assertDontSee('Отзыв на модерации');
});

it('drops the reviews section when nothing is published', function () {
    Review::factory()->pending()->create();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Отзывы доказывают результат');
});

it('caps the services showcase per category but keeps every position in the form select', function () {
    // Прямой сторож асимметрии решения 3: витрина усечена, селект — нет.
    // Обратное было бы дефектом — клик по видимой строке подставлял бы
    // несуществующую опцию, и Alpine молча ничего не сделал бы.
    $limit = (int) config('home.services_per_category');

    foreach (range(1, $limit) as $position) {
        Service::factory()->maintenance()->create([
            'title' => "Работа {$position}",
            'sort_order' => $position,
        ]);
    }

    $overflow = Service::factory()->maintenance()->create([
        'title' => 'Работа сверх витрины',
        'sort_order' => $limit + 1,
    ]);

    $response = $this->get('/')->assertOk();

    // На витрине строки нет: строка прайса опознаётся по обработчику
    // подстановки, а не по названию — название встречается ещё и в селекте.
    $response->assertDontSee('pick('.$overflow->getKey().')', escape: false);

    // В селекте опция есть: набор селекта — надмножество витрины.
    $response->assertSee('<option value="'.$overflow->getKey().'"', escape: false)
        ->assertSee('Работа сверх витрины');
});

it('drops a service category without published positions from the showcase', function () {
    Service::factory()->maintenance()->create(['title' => 'Живая работа']);
    Service::factory()->tireService()->unpublished()->create(['title' => 'Снятая с публикации работа']);

    // Категория проверяется по подписи, которой больше нигде на странице
    // нет: «Детейлинг» для этого не годится — так называется одна из плашек
    // экосистемы, и тест прошёл бы вхолостую.
    $this->get('/')
        ->assertOk()
        ->assertSee(ServiceCategory::Maintenance->label())
        ->assertSee('Живая работа')
        ->assertDontSee(ServiceCategory::TireService->label())
        ->assertDontSee('Снятая с публикации работа');
});

it('keeps the chosen service selected after a validation error', function () {
    // Правило вехи 3.7 «old() во всех полях без исключения» распространяется
    // и на селект. Это же сторож против `x-model` на нём: порчу в браузере
    // тест разметки не поймает — Alpine затирает значение уже после ответа
    // сервера, — но он ловит обратное: исчезновение серверного `@selected`,
    // без которого затирать будет нечего и симптом станет постоянным.
    $service = Service::factory()->detailing()->create(['title' => 'Полировка кузова']);

    // `followingRedirects()`, а не отдельный `get()` после `post()`: флеш
    // ошибок к следующему запросу теста уже состарен, и проверка «форма
    // вернулась с сохранённым выбором» прошла бы вхолостую.
    $this->from('/')
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

it('hides the status filter until alpine boots', function () {
    // Кнопки фильтра без скрипта не делают НИЧЕГО, а контрол, который ничего
    // не делает, обманывает. Без JS панели нет, и видны все карточки.
    Car::factory()->onHomepage()->create(['status' => CarStatus::InStock]);
    Car::factory()->onHomepage()->create(['status' => CarStatus::OnOrder]);

    $html = $this->get('/')->assertOk()->getContent();

    // `x-cloak` обязан стоять на ТОМ ЖЕ теге, что и панель: на соседнем
    // он скрыл бы не её, и проверка на простое наличие атрибута
    // на странице прошла бы вхолостую.
    expect($html)->toMatch('/x-cloak[^>]*aria-label="Фильтр по статусу"/u');
});

it('drops the status filter when the selection has a single status', function () {
    // Фильтровать нечего: две кнопки над одинаковой выдачей выглядят
    // сломанными.
    Car::factory()->onHomepage()->count(2)->create(['status' => CarStatus::InStock]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Фильтр по статусу');
});

it('drops the quick selector when the catalog is empty', function () {
    // Обе границы цены `null` — слайдер «от null до null» был бы контролом
    // без данных.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('Автомобиль под ваш бюджет');
});

it('prints the server side count and a plain catalog link in the quick selector', function () {
    // Счётчик считает по всему доступному каталогу, а не по подборке
    // главной, поэтому отметка `onHomepage` здесь не ставится.
    Car::factory()->count(3)->create(['status' => CarStatus::InStock, 'price' => 3_000_000]);
    Car::factory()->sold()->create(['price' => 9_000_000]);

    $html = $this->get('/')->assertOk()->getContent();

    // Без JavaScript счётчик печатает серверное число: проданный
    // автомобиль в него не входит.
    expect($html)->toMatch('/x-text="matches"\s*>3</');

    // Кнопка несёт настоящий `href` в разметке, а не только `x-bind:href`:
    // без скрипта она обязана вести в каталог, а не никуда.
    expect($html)->toMatch('~href="[^"]*/catalog"\s+x-bind:href="href"~');
});

it('does not add a query per review or price position', function () {
    // Прогрев обязателен: кеш настроек сбрасывается перед каждым тестом,
    // и промах на первом запросе добавил бы ему один лишний запрос.
    warmSettingsCache();

    Car::factory()->onHomepage()->create();
    Review::factory()->published()->create();
    Service::factory()->maintenance()->create();

    $few = countQueries(fn () => $this->get('/')->assertOk());

    Review::factory()->published()->count(5)->create();
    Service::factory()->maintenance()->count(5)->create();
    Service::factory()->detailing()->count(3)->create();

    $many = countQueries(fn () => $this->get('/')->assertOk());

    // Нижняя граница обязательна — правило `RULES.md`: выборка, не поймавшая
    // ни одного запроса, иначе проходит вхолостую.
    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('hides the duplicated ticker copy from screen readers', function () {
    Setting::set('home.ticker', ['Единственный тезис ленты']);

    $html = $this->get('/')->assertOk()->getContent();

    // Лента едет `translateX(-50%)`, поэтому копий ровно две и они обязаны
    // совпадать. Вторая несёт `aria-hidden`, иначе скринридер читает
    // тезисы дважды подряд.
    expect(substr_count($html, 'Единственный тезис ленты'))->toBe(2);
});
