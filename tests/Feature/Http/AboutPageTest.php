<?php

use App\Models\Employee;
use App\Models\Media;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/*
 * Страница «О компании» (веха 4.5).
 *
 * Сквозное — 200, заявка, отсутствие полей запчастей — проверяет
 * `SectionPagesTest`: адрес `/about` дописан в три его перечня явно, потому
 * что они перечислены руками и новая страница сама туда не попадает. Здесь
 * проверяется то, что есть только у этой страницы.
 */

beforeEach(function (): void {
    resetRateLimiters();
});

it('takes the heading and intro from site settings, not from a constant', function () {
    // Сторож правила проекта: H1 и вводный текст страниц разделов
    // редактирует заказчик. Константа из макета отобрала бы у него поле,
    // которое ему уже отдали на `/services` и `/parts`, — и он заметил бы
    // асимметрию первым. Парные проверки — в `SectionPagesTest`.
    Setting::set('about_page.intro_title', 'Проверочный заголовок компании');
    Setting::set('about_page.intro_text', 'Проверочное вступление компании.');

    $this->get('/about')
        ->assertOk()
        ->assertSee('Проверочный заголовок компании')
        ->assertSee('Проверочное вступление компании.');
});

it('falls back to a default heading when the setting is cleared, not left empty', function () {
    // Правило `RULES.md`: второй аргумент `Setting::get()` срабатывает
    // только на отсутствующий ключ, а форма настроек пишет пустую строку
    // как есть — «очистить блок» там рабочий сценарий. Без нормализации
    // страница получила бы пустой `<h1>` и `<title>` из одного разделителя:
    // сломанный вид при живой настройке и без единой ошибки в логе.
    //
    // Проверять `assertSee('О компании')` здесь БЕСПОЛЕЗНО: эта же строка
    // стоит подписью пункта меню в шапке и в подвале, надзаголовком над H1
    // и прошла бы при пустом заголовке. Смотреть нужно внутрь самого `<h1>`.
    Setting::set('about_page.intro_title', '');

    $html = $this->get('/about')->assertOk()->getContent();

    expect($html)->toMatch('/<h1[^>]*>\s*О компании\s*<\/h1>/u')
        ->and($html)->toContain('<title>О компании — ');
});

it('shows published employees and hides unpublished ones', function () {
    Employee::factory()->create(['name' => 'Опубликованный Сотрудник']);
    Employee::factory()->unpublished()->create(['name' => 'Скрытый Сотрудник']);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Опубликованный Сотрудник')
        ->assertDontSee('Скрытый Сотрудник');
});

it('shows published reviews and hides the ones awaiting moderation', function () {
    // Немодерированный отзыв на сайте — дефект, и путь к нему закрыт
    // скоупом в сервисе, а не условием в шаблоне.
    Review::factory()->published()->create(['author_name' => 'Опубликованный Автор']);
    Review::factory()->pending()->create(['author_name' => 'Ожидающий Автор']);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Опубликованный Автор')
        ->assertDontSee('Ожидающий Автор');
});

it('shows every published review, without the homepage limit', function () {
    // `config/home.php` резервирует полный список за этой страницей дословно:
    // «Полный список отзывов — задача страницы „О компании“, а не главной».
    // Отзывов заводится БОЛЬШЕ лимита главной — иначе тест прошёл бы
    // и с переиспользованным `home.reviews_limit`.
    $limit = (int) config('home.reviews_limit');

    expect($limit)->toBeGreaterThan(0);

    $reviews = Review::factory()->published()->count($limit + 2)->create();

    $response = $this->get('/about')->assertOk();

    foreach ($reviews as $review) {
        $response->assertSee($review->author_name);
    }
});

it('drops the team block entirely when nobody is published', function () {
    // Правило проекта: блок, управляемый данными, при пустом значении
    // не рендерится ВОВСЕ — ни заголовка, ни пустой сетки. Заголовок
    // «Команда» над пустым местом читается как поломка, а не как
    // «пока никого не завели».
    Employee::factory()->unpublished()->create();

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('Специалисты, которые ведут');
});

it('warns in the log when the team block disappears', function () {
    // Снаружи отказ неотличим от «так и задумано»: страница отдаёт 200
    // и выглядит работающей. Заметить незаполненную админку больше нечем.
    Log::spy();

    $this->get('/about')->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'опубликованного сотрудника'))
        ->atLeast()->once();
});

it('renders a placeholder instead of the photo for an employee without one', function () {
    // Сторож обещания из справки сотруднику (`resources/help/team-page.md`):
    // «Карточка без фотографии выглядит нормально — на её месте
    // показывается заглушка». Приём карточки отзыва на главной (нет фото —
    // нет тега `<img>`) превратил бы эту статью в ложь в момент открытия
    // страницы, и разошлись бы они молча.
    Employee::factory()->create(['name' => 'Пётр Безфото', 'media_id' => null]);

    $html = $this->get('/about')->assertOk()->getContent();

    expect($html)->toContain('Пётр Безфото')
        // Заглушка стоит на том же токене и в той же геометрии, что и снимок.
        ->and($html)->toContain('rounded-card bg-photo');
});

it('drops the history block when the repeater comes back null', function () {
    // Та самая ловушка, из-за которой нормализация живёт в сервисе:
    // удаление всех элементов в форме настроек даёт `null`, а не `[]`,
    // и шаблон, написанный по сиду, упал бы на первом же `foreach (null)` —
    // на проде, где настройки правят, а не в тестах.
    //
    // Блок сначала ЗАПОЛНЯЕТСЯ: в тестовой базе настроек нет, и проверка
    // одного только `null` прошла бы вхолостую — история и так пуста.
    Setting::set('about_page.history', [
        ['year' => '2018', 'title' => 'Проверочная веха', 'text' => 'Проверочный текст вехи.'],
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Проверочная веха')
        ->assertSee('От первых поставок');

    Setting::set('about_page.history', null);

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('Проверочная веха')
        ->assertDontSee('От первых поставок');
});

it('drops a history item without a title but keeps the year optional', function () {
    // Элемент без заголовка выпадает — карточка из одного года читается
    // как поломка вёрстки. Год при этом необязателен: веха без даты
    // остаётся вехой. Правило то же, что у `home.advantages`.
    Setting::set('about_page.history', [
        ['year' => '2018', 'title' => '', 'text' => 'Текст без заголовка.'],
        ['year' => '', 'title' => 'Веха без года', 'text' => 'Текст вехи без года.'],
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('Текст без заголовка.')
        ->assertSee('Веха без года')
        ->assertSee('Текст вехи без года.');
});

it('drops the mission block only when both of its fields are cleared', function () {
    // Форма значения и правило те же, что у `home.promo`: одно заполненное
    // поле блок оставляет, оба пустых — убирают. «Миссия» здесь надёжный
    // маркер, в отличие от «О компании»: слова нет ни в меню, ни в подвале.
    Setting::set('about_page.mission', [
        'title' => 'Проверочная миссия',
        'text' => 'Проверочный текст миссии.',
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Проверочная миссия')
        ->assertSee('Проверочный текст миссии.');

    Setting::set('about_page.mission', ['title' => '', 'text' => 'Один текст без заголовка.']);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Один текст без заголовка.')
        ->assertSee('Миссия');

    Setting::set('about_page.mission', ['title' => '', 'text' => '']);

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('Миссия');
});

it('loads employee and review photos without an N+1', function () {
    // Сторож против запроса на карточку. PHPDoc `Employee::photoUrl()`
    // требует `with('media')` дословно, и потерять его при правке сервиса
    // проще всего: страница остаётся рабочей, дорожает только незаметно.
    // Прецедент — сторож этапов покупки вехи 4.9, поймавший 16 запросов
    // против 11.
    //
    // Прогрев обязателен: кеш настроек сбрасывается перед каждым тестом,
    // и первый HTTP-запрос платил бы за промах лишним запросом — разница
    // в единицу читалась бы как N+1 там, где его нет.
    $media = Media::factory()->count(3)->create();

    Employee::factory()->create(['media_id' => $media[0]->getKey()]);
    Review::factory()->published()->create(['media_id' => $media[1]->getKey()]);

    warmSettingsCache();

    $small = countQueries(fn () => $this->get('/about')->assertOk());

    // Нижняя граница обязательна — правило `RULES.md`: страница, которая
    // не отрендерилась, тоже дала бы маленький счётчик.
    expect($small)->toBeGreaterThan(0);

    Employee::factory()->count(6)->create(['media_id' => $media[2]->getKey()]);
    Review::factory()->published()->count(6)->create(['media_id' => $media[2]->getKey()]);

    $large = countQueries(fn () => $this->get('/about')
        ->assertOk()
        ->assertSee('Специалисты, которые ведут', escape: false));

    // Число запросов не зависит от размера выдачи — именно этим N+1
    // отличается от «просто нескольких запросов».
    expect($large)->toBe($small);
});

it('carries its own meta tags, not the site-wide defaults', function () {
    // Заголовок собирается из настройки страницы, а описание живёт
    // в шаблоне — как у `/services`, `/parts` и `/contacts`. Общий фолбэк
    // на `seo.default_*` тут был бы неправ: он маскировал бы забытую
    // секцию вместо того, чтобы её обнажить. Разбор — в PHPDoc
    // `HomeContent::seo()`, где то же решение записано с другой стороны.
    Setting::set('about_page.intro_title', 'Заголовок для сниппета');
    Setting::set('seo.default_title', 'Умолчание, которого тут быть не должно');

    $html = $this->get('/about')->assertOk()->getContent();

    expect($html)->toContain('<title>Заголовок для сниппета — ')
        ->and($html)->not->toContain('Умолчание, которого тут быть не должно')
        ->and($html)->toMatch('/<meta name="description" content="[^"]+"/u');
});

it('keeps exactly one lead-form anchor on the page', function () {
    // Кнопка «Оставить заявку» в шапке ведёт на `#lead-form` на КАЖДОЙ
    // странице сайта, а якорь появляется только вместе с `x-lead-section`.
    // Страница без секции даёт мёртвую кнопку — без ошибок, без записи
    // в лог, заметно только кликом. Двойной якорь ломает её так же.
    $html = $this->get('/about')->assertOk()->getContent();

    expect(substr_count($html, 'id="lead-form"'))->toBe(1);
});
