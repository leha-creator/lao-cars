<?php

use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Setting;

/*
 * Главная (веха 4.2).
 *
 * Страница собирается из настроек и каталога, и каждый её блок обязан уметь
 * отрендериться пустым: форма настроек пишет свои ключи безусловно, даже
 * пустыми, а очищенный репитер приходит `null`, а не `[]`. Шаблон,
 * написанный по сиду, падает на первом же `foreach (null)` — и падает
 * на проде, а не здесь, если тесты тоже написаны по сиду.
 *
 * Файл не отправляет форму заявки, поэтому `resetRateLimiters()` ему
 * не нужен. Если здесь появится `post(route('leads.store'))`, `beforeEach`
 * с ним становится обязательным — правило `RULES.md`.
 */

/**
 * Сколько карточек авто на странице.
 *
 * Считается по ссылкам на карточку автомобиля: `/catalog/` с завершающим
 * слэшем есть только у них, а ссылка «Весь каталог →» ведёт на `/catalog`
 * без него. Заголовки для счёта не годятся — `h3` теперь и у преимуществ,
 * и у плашек направлений.
 */
function carCardCount(string $html): int
{
    return substr_count($html, '/catalog/');
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

it('hides the duplicated ticker copy from screen readers', function () {
    Setting::set('home.ticker', ['Единственный тезис ленты']);

    $html = $this->get('/')->assertOk()->getContent();

    // Лента едет `translateX(-50%)`, поэтому копий ровно две и они обязаны
    // совпадать. Вторая несёт `aria-hidden`, иначе скринридер читает
    // тезисы дважды подряд.
    expect(substr_count($html, 'Единственный тезис ленты'))->toBe(2);
});
