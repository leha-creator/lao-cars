<?php

use App\Models\Setting;
use App\Support\Typography;
use App\Support\WorkSchedule;

/*
 * Страница контактов (веха 4.5, вторая половина вехи).
 *
 * Сквозное — 200, заявка с неё, отсутствие полей подбора запчастей —
 * проверяет `SectionPagesTest`: адрес `/contacts` стоит во всех трёх его
 * перечнях с вехи 4.1. Здесь — то, что есть только у этой страницы.
 *
 * Разбор адреса карты по хостам живёт отдельно, в `MapEmbedTest`: там
 * проверяются значения, а не HTML, и утонуть среди проверок разметки
 * тому списку нельзя. Здесь — только то, ЧТО из его результата доезжает
 * до страницы.
 */

beforeEach(function (): void {
    resetRateLimiters();
});

it('takes the heading and intro from site settings, not from a constant', function () {
    // Сторож того же правила, что и на трёх соседних страницах: H1 и
    // вводный текст редактирует заказчик. До вехи 4.5 `/contacts` была
    // единственной из четырёх, где они стояли константами в шаблоне.
    Setting::set('contacts_page.intro_title', 'Проверочный заголовок контактов');
    Setting::set('contacts_page.intro_text', 'Проверочное вступление контактов.');

    $this->get('/contacts')
        ->assertOk()
        ->assertSee('Проверочный заголовок контактов')
        // Вступление проходит типографику в `x-page-heading`, заголовок — нет.
        ->assertSee(Typography::tie('Проверочное вступление контактов.'));
});

it('falls back to a default heading when the setting is cleared, not left empty', function () {
    // Правило `RULES.md`: второй аргумент `Setting::get()` срабатывает
    // только на отсутствующий ключ, а форма настроек пишет пустую строку
    // как есть — «очистить блок» там рабочий сценарий.
    //
    // Проверять `assertSee('Контакты')` здесь БЕСПОЛЕЗНО: та же строка
    // стоит подписью пункта меню в шапке и подвале и надзаголовком над H1,
    // и прошла бы при пустом заголовке. Смотреть надо внутрь `<h1>`.
    Setting::set('contacts_page.intro_title', '');

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->toMatch('/<h1[^>]*>\s*Контакты\s*<\/h1>/u')
        ->and($html)->toContain('<title>Контакты — ');
});

it('makes the phone and the email clickable', function () {
    // До вехи 4.5 номер и почта на КАНОНИЧЕСКОЙ странице контактов
    // выводились текстом — при том что в шапке и подвале были ссылками.
    // С телефона нажатие здесь и есть главный сценарий страницы.
    Setting::set('contacts.phone', '+7 (495) 123-45-67');
    Setting::set('contacts.email', 'info@laocars.ru');

    $this->get('/contacts')
        ->assertOk()
        // Скобки, пробелы и дефисы из адреса ссылки уходят — `PhoneLink`.
        ->assertSee('href="tel:+74951234567"', escape: false)
        ->assertSee('href="mailto:info@laocars.ru"', escape: false);
});

it('shows the phone without a link when it cannot make one', function () {
    // Номер из одних скобок даёт после чистки пустую строку, а `href="tel:"`
    // выглядит рабочей ссылкой и ведёт в никуда: звонилка открывается
    // с пустым полем. Подпись при этом остаётся — данные-то есть.
    Setting::set('contacts.phone', '( ) —');

    $html = $this->get('/contacts')->assertOk()->getContent();

    // Проверяется карточка страницы, а не вся разметка: шапка и подвал
    // на такой номер по-прежнему печатают пустой `tel:` — поведение,
    // которое старше этой вехи и живёт в общем layout. Расширять сюда
    // проверку значило бы поймать чужой дефект тестом страницы контактов
    // и молча взять на себя правку шапки; он записан открытым вопросом 4
    // в `.ai-factory/plans/contacts-page.md`.
    preg_match('/<div class="rounded-card[^"]*">(?:(?!<\/section>).)*?Телефон.*?<\/div>\s*<\/div>/su', $html, $card);

    expect($card)->not->toBeEmpty()
        ->and($card[0])->toContain('( ) —')
        ->and($card[0])->not->toContain('href="tel:"');
});

it('drops a contact card when its setting is cleared', function () {
    // Правило проекта: блок без данных не рендерится вовсе. Пустая
    // карточка с подписью «E-mail» и пустотой под ней читается как
    // поломка вёрстки, а не как «почту не указали».
    Setting::set('contacts.email', '');
    Setting::set('contacts.phone', '');

    $html = $this->get('/contacts')->assertOk()->getContent();

    // Подписи карточек, а не значения: значения пусты, и искать в HTML
    // нечего. Подпись «Телефон» при этом есть и в шапке — поэтому
    // проверяется разметка карточки, уникальная для этой страницы.
    expect($html)->not->toContain('>E-mail</div>');
});

it('lists the schedule day by day, not as the footer one-liner', function () {
    // Подвал показывает то же расписание склеенным — там на него отведена
    // строка. Страница контактов каноничная для организации, и семь строк
    // здесь читаются лучше, особенно когда дни разные.
    Setting::set('contacts.schedule', [
        'days' => [
            'mon' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'],
            'tue' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'],
            'wed' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'],
            'thu' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'],
            'fri' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'],
            'sat' => ['closed' => false, 'open' => '10:00', 'close' => '16:00'],
            'sun' => ['closed' => true, 'open' => null, 'close' => null],
        ],
        'note' => 'В праздничные дни по записи.',
    ]);

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->toContain('Понедельник')
        ->and($html)->toContain('Суббота')
        // Ведущий ноль снимает `WorkSchedule::humanTime()`, и строка обязана
        // совпадать с подвалом до символа: обе собирает один класс.
        // «09:00–19:00» здесь означало бы вторую реализацию форматирования.
        ->and($html)->toContain('9:00–19:00')
        ->and($html)->toContain('10:00–16:00')
        ->and($html)->toContain('Выходной')
        ->and($html)->toContain('В праздничные дни по записи.');
});

it('collapses a uniform week into one line instead of seven identical rows', function () {
    // Так компания работает СЕГОДНЯ, то есть это основной случай, а не
    // краевой. Семь строк «9:00–21:00» подряд не несут ни бита сверх
    // «Без выходных, 9:00–21:00» и читаются как невыполненная работа
    // вёрстки. Выбор формы делает `WorkSchedule::rows()`, а не шаблон.
    Setting::set('contacts.schedule', WorkSchedule::defaultSetting());

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->toContain('Без выходных, 9:00–21:00')
        // Заголовок блока на месте — секция не исчезла, свернулась
        // только её таблица.
        ->and($html)->toMatch('/<h2[^>]*>\s*Часы работы\s*<\/h2>/u')
        ->and($html)->not->toContain('Понедельник');
});

it('drops the schedule block when no day is a working day', function () {
    // Таблица из семи «выходных» читается как поломка данных, а не как
    // «часы не заданы». WARN про это пишет сам `WorkSchedule`.
    $days = [];

    foreach (array_keys(WorkSchedule::defaultSetting()['days']) as $day) {
        $days[$day] = ['closed' => true, 'open' => null, 'close' => null];
    }

    Setting::set('contacts.schedule', ['days' => $days, 'note' => null]);

    // Проверяется ЗАГОЛОВОК блока, а не подстрока «Часы работы»: та же
    // пара слов стоит в meta-описании страницы, и проверка на подстроку
    // была бы красной всегда — независимо от того, рендерится блок или нет.
    expect($this->get('/contacts')->assertOk()->getContent())
        ->not->toMatch('/<h2[^>]*>\s*Часы работы\s*<\/h2>/u');
});

it('builds the map from the address when no widget url is configured', function () {
    Setting::set('contacts.address', 'Москва, Тестовая, 2');
    Setting::set('contacts.map_embed', '');

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->toContain('https://yandex.ru/map-widget/v1/?')
        ->and($html)->toContain('Как нас найти');
});

it('prefers the configured widget url over the address', function () {
    Setting::set('contacts.address', 'Москва, Тестовая, 2');
    Setting::set('contacts.map_embed', 'https://yandex.ru/map-widget/v1/?um=constructor%3Aabc');

    expect($this->get('/contacts')->assertOk()->getContent())
        ->toContain('um=constructor%3Aabc');
});

it('never puts a foreign host into the map frame', function () {
    // Главная проверка страницы. Значение настройки едет прямо в `src`
    // нашего iframe: пропущенный сюда чужой адрес — это чужая страница
    // под нашим доменом в глазах посетителя, а не опечатка в вёрстке.
    //
    // Настройку сюда кладём МИМО формы админки (её правило — второй рубеж,
    // и оно проверяется в `ManageSiteSettingsTest`): так значение приезжает
    // из сида, миграции или `psql`, то есть ровно тем путём, ради которого
    // проверка продублирована на выводе.
    Setting::set('contacts.address', 'Москва, Тестовая, 2');
    Setting::set('contacts.map_embed', 'https://example.com/map');

    $html = $this->get('/contacts')->assertOk()->getContent();

    expect($html)->not->toContain('example.com')
        // Страница при этом не остаётся без карты: посетитель не должен
        // платить за опечатку администратора пустым блоком.
        ->and($html)->toContain('https://yandex.ru/map-widget/v1/?');
});

it('drops the map section when there is no address and no widget url', function () {
    Setting::set('contacts.address', '');
    Setting::set('contacts.map_embed', '');

    expect($this->get('/contacts')->assertOk()->getContent())
        ->not->toContain('<iframe');
});

it('keeps the page light all the way down', function () {
    // Веха 4.11: все четыре внутренние страницы светлые целиком, и
    // `theme-light` обязан стоять на КАЖДОЙ секции, а не на обёртке вокруг
    // них — обёртка получила бы боковые поля страницы и оставила бы по краям
    // тёмные полосы. Секцию заявки красит сам `x-lead-section`.
    Setting::set('contacts.address', 'Москва, Тестовая, 2');

    $html = $this->get('/contacts')->assertOk()->getContent();

    // Проверка через ОТРИЦАНИЕ, по образцу сторожа блока этапов
    // в `HomePageTest`: положительная проверка на `theme-light` прошла бы
    // и при секции, забытой без него, — их на странице несколько.
    preg_match_all('/<section[^>]*>/', $html, $sections);

    $unpainted = array_filter(
        $sections[0],
        static fn (string $tag): bool => ! str_contains($tag, 'theme-light') && ! str_contains($tag, 'id="lead-form"'),
    );

    expect($unpainted)->toBe([]);
});
