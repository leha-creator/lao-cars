<?php

use App\Enums\CarStatus;
use App\Enums\ServiceCategory;
use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Media;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

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

/**
 * Разметка секции «Как проходит покупка» — от её тега до ближайшего закрытия.
 *
 * Резать страницу обязательно: сторожа вехи 4.12 утверждают про блок то,
 * что для остальной страницы неверно, — например отсутствие `text-accent`
 * у кружка номера при живом `text-accent` в надзаголовке той же секции.
 *
 * Вложенных `<section>` внутри блока нет, поэтому ближайшее закрытие и есть
 * конец секции. Появится вложенная — тест начнёт резать по её закрытию,
 * то есть отрежет слишком мало и покраснеет, а не пройдёт вхолостую.
 */
function processSectionHtml(string $html): string
{
    $start = strpos($html, '<section id="process"');

    expect($start)->not->toBeFalse('секция `#process` не найдена в разметке страницы');

    $end = strpos($html, '</section>', (int) $start);

    expect($end)->not->toBeFalse('у секции `#process` не найдено закрытие');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/**
 * Открывающий тег обёртки-пина — той, что несёт `x-data` ленты этапов.
 *
 * Тег ищется посимвольно, а не регуляркой `<div[^>]*>`: значение `x-data`
 * содержит выражения со знаком «больше» (`this.travel > 160`), и жадный
 * поиск закрывающей скобки обрывает тег на середине атрибута. Сторож
 * при этом не падает, а проверяет огрызок — то есть проходит вхолостую.
 */
function pinWrapperTag(string $html): string
{
    $anchor = strpos($html, 'x-data=');

    expect($anchor)->not->toBeFalse('в секции `#process` нет элемента с `x-data`');

    $start = strrpos(substr($html, 0, (int) $anchor), '<');

    expect($start)->not->toBeFalse('у элемента с `x-data` не найдено начало тега');

    $quote = null;

    for ($i = (int) $start; $i < strlen($html); $i++) {
        $char = $html[$i];

        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;

            continue;
        }

        if ($char === '>') {
            return substr($html, (int) $start, $i - (int) $start + 1);
        }
    }

    return '';
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

it('calls the first trust card a showroom, not a physical salon', function () {
    // Формулировку поправил сам заказчик на демонстрации 11.08.2026: «Не
    // физический салон, а шоу-рум в Москве. — У нас и шоу-рум, и сервис».
    //
    // Сторожа у этой строки не было НИ ОДНОГО: полосу доверия набор
    // не проверял вовсе, и откат формулировки прошёл бы незамеченным.
    // Тексты карточек живут в шаблоне намеренно (веха 4.6) — значит
    // и проверять их больше негде.
    $this->get('/')
        ->assertOk()
        ->assertSee('Шоу-рум в Москве')
        ->assertDontSee('Физический салон');
});

it('adds the showroom photo to the trust band and keeps the band without it', function () {
    // Фотография — единственное, чем полоса доверия управляется из админки.
    // Пустая настройка убирает ФОТОПАНЕЛЬ, но не блок: это осознанное
    // исключение из правила «блок без данных не рендерится вовсе», и без
    // сторожа его «поправят» под общее правило вместе с полосой.
    $this->get('/')
        ->assertOk()
        ->assertSee('Шоу-рум в Москве')
        ->assertDontSee('alt="Шоу-рум ЛАО КАРС в Москве"', escape: false);

    $media = Media::factory()->create();

    Setting::set('home.trust', ['image_id' => $media->getKey()]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Шоу-рум в Москве')
        ->assertSee('alt="Шоу-рум ЛАО КАРС в Москве"', escape: false);
});

it('grounds the trust heading and glass cards on scrims over the photo', function () {
    // Поверх кадра лежат СВЕТЛЫЕ чернила заголовка и стеклянные карточки,
    // а `--color-ink` и `--color-surface` в светлой секции тёмный и белый
    // соответственно. Держится это на паре «затемнение + `--color-on-photo`»,
    // и обе маски нужны: верхняя под заголовок, нижняя под карточки.
    //
    // Отказ молчаливый вдвойне. Во-первых, сборка зелёная и классы на месте.
    // Во-вторых, на ТЕКУЩЕЙ фотографии (ночной кадр) всё выглядит нормально
    // и без затемнения — сломается это на первом же дневном снимке, который
    // заказчик загрузит сам, без разработчика рядом.
    $media = Media::factory()->create();

    Setting::set('home.trust', ['image_id' => $media->getKey()]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('bg-gradient-to-b from-scrim/85')
        ->and($html)->toContain('bg-gradient-to-t from-scrim/85')
        // Светлые чернила заголовка — вторая половина той же пары.
        ->and($html)->toContain('text-on-photo')
        // Стекло: полупрозрачная заливка, светлая кромка и размытие.
        // Без размытия сквозь карточку проступает кадр и спорит с текстом.
        ->and($html)->toContain('lg:bg-on-photo/12')
        ->and($html)->toContain('lg:border-on-photo/20')
        ->and($html)->toContain('lg:backdrop-blur-lg');
});

it('promotes the first trust item to a heading over the photo and keeps three cards', function () {
    $media = Media::factory()->create();

    Setting::set('home.trust', ['image_id' => $media->getKey()]);

    $html = $this->get('/')->assertOk()->getContent();

    // Первый пункт стал заголовком блока — до вехи 4.5 заголовка
    // у полосы доверия не было вовсе.
    expect($html)->toMatch('/<h2[^>]*text-on-photo[^>]*>\s*Шоу-рум в Москве\s*<\/h2>/u');

    // И в карточках его больше нет: иначе строка стояла бы на странице
    // дважды — заголовком и карточкой.
    $cards = mb_substr($html, (int) mb_strpos($html, 'Прозрачный состав цены'));

    expect($cards)->not->toContain('Шоу-рум в Москве');
});

it('returns the showroom line to the cards when the photo setting is cleared', function () {
    // Главное про эту развилку: очищенная настройка НЕ уносит текст с сайта.
    // Первый пункт возвращается четвёртой карточкой, а не исчезает вместе
    // с фотографией, — иначе пустое поле в админке молча удаляло бы строку,
    // которую туда никто не писал.
    Setting::set('home.trust', ['image_id' => null]);

    $response = $this->get('/')->assertOk();

    foreach ([
        'Шоу-рум в Москве',
        'Прозрачный состав цены',
        'Фото- и видеоотчёт',
        'Собственная экосистема',
    ] as $title) {
        $response->assertSee($title);
    }

    // Заголовка при этом нет: без кадра под ним первый пункт — такая же
    // карточка, как остальные три, а не заголовок над ними.
    expect($response->getContent())->not->toContain('text-on-photo');
});

it('keeps the trust band up when the showroom photo was deleted from the library', function () {
    // Тот же отказ, что у картинки этапа, и та же причина для WARN:
    // снаружи он неотличим от незаполненного поля — полоса просто
    // остаётся текстовой.
    Log::spy();

    $media = Media::factory()->create();
    $id = $media->getKey();
    $media->forceDelete();

    Setting::set('home.trust', ['image_id' => $id]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Шоу-рум в Москве')
        ->assertDontSee('alt="Шоу-рум ЛАО КАРС в Москве"', escape: false);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'фотография шоу-рума в полосе доверия')
            && $context['setting'] === 'home.trust.image_id'
            && $context['media_id'] === $id)
        ->once();
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

it('illustrates a step and keeps a step without an image textual', function () {
    // Главный сторож вехи: изображение НЕобязательно. Обратное правило
    // сносило бы с сайта этап, которому картинку ещё не подобрали, —
    // и симптом читался бы как поломка сохранения формы.
    $media = Media::factory()->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап с картинкой', 'text' => 'Текст первого.', 'image_id' => $media->getKey()],
        ['number' => '02', 'title' => 'Этап без картинки', 'text' => 'Текст второго.', 'image_id' => null],
    ]);

    $html = $this->get('/')
        ->assertOk()
        ->assertSee('Этап с картинкой')
        // Этап без картинки остаётся на странице целиком — с номером,
        // заголовком и текстом.
        ->assertSee('Этап без картинки')
        ->assertSee('Текст второго.')
        ->assertSee($media->url)
        // Осмысленный `alt`: картинка несёт смысл, а не декорирует.
        ->assertSee('alt="Этап покупки: Этап с картинкой"', escape: false)
        ->getContent();

    // Ровно одна картинка на два этапа, а не одна на каждый.
    expect(substr_count($html, $media->url))->toBe(1)
        // Блок восьмой из четырнадцати — заведомо ниже сгиба, и ленивая
        // загрузка здесь не микрооптимизация: шесть иллюстраций иначе
        // конкурируют за канал с фоном первого экрана.
        ->and($html)->toContain('src="'.$media->url.'"');

    expect(preg_match(
        '#<img[^>]*'.preg_quote($media->url, '#').'[^>]*loading="lazy"#',
        // Атрибуты разнесены по строкам — переносы для проверки
        // схлопываются в пробел.
        (string) preg_replace('/\s+/', ' ', $html),
    ))->toBe(1);
});

it('does not add a query per step illustration', function () {
    // Шесть картинок — это ровно то число, при котором приём
    // `promoImageUrl()` (`find()` на каждый вызов) копируют дальше
    // и получают N+1 на главной странице сайта.
    warmSettingsCache();

    $images = Media::factory()->count(6)->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Первый этап', 'image_id' => $images[0]->getKey()],
    ]);

    $few = countQueries(fn () => $this->get('/')->assertOk()->assertSee('Первый этап'));

    Setting::set('home.steps', $images
        ->map(fn (Media $media, int $i): array => [
            'number' => '0'.($i + 1),
            'title' => 'Этап номер '.($i + 1),
            'image_id' => $media->getKey(),
        ])
        ->all());

    $many = countQueries(fn () => $this->get('/')->assertOk()->assertSee('Этап номер 6'));

    // Нижняя граница обязательна — правило `RULES.md`: выборка,
    // не поймавшая ни одного запроса, иначе проходит вхолостую.
    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('keeps the homepage up when a step image was deleted from the library', function () {
    // Штатным путём сюда не попасть — удаление используемой записи
    // блокирует `Media::usages()`. Значит запись пропала в обход админки,
    // и это единственный отказ блока, который снаружи неотличим
    // от незаполненного поля: карточка просто останется текстовой.
    Log::spy();

    $media = Media::factory()->create();
    $id = $media->getKey();
    $media->forceDelete();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап с потерянной картинкой', 'text' => 'Текст.', 'image_id' => $id],
    ]);

    $this->get('/')
        ->assertOk()
        // Карточка на месте: пропала картинка, а не этап.
        ->assertSee('Этап с потерянной картинкой')
        ->assertSee('Текст.');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Главная] этап покупки ссылается на удалённую запись медиабиблиотеки'
            && $context['step'] === 'Этап с потерянной картинкой'
            && $context['media_id'] === $id)
        ->once();
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

it('stands the steps section on the light branch of the palette', function () {
    // Веха 4.12: блок переехал с `deep` в светлую ветку. Отказ здесь тихий —
    // сборка зелёная, классы на месте, в тёмной теме всё как было, — поэтому
    // сторож нужен именно на разметке, а не на глаз.
    //
    // Место выбрано не случайно: `PaletteGuardTest` проверяет правила, общие
    // для ВСЕХ публичных шаблонов (литерал `white`, `text-page`, `bg-accent`),
    // а это утверждение про один блок главной.
    $media = Media::factory()->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап на светлом', 'text' => 'Текст этапа.', 'image_id' => $media->getKey()],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    // Проверка через отрицание обязательна: положительная проверка
    // на `theme-light` прошла бы и при забытом `bg-deep`, который её
    // молча перебивает светло-серым.
    expect($section)->toContain('theme-light')
        ->and($section)->not->toContain('bg-deep');

    // Карточка на `surface`, а не на `page`: в светлой ветке `page` у секции
    // и `page` у карточки — один и тот же цвет, и карточка перестаёт
    // читаться приподнятой. Теней в дизайн-системе нет.
    expect($section)->toMatch('/<article[^>]*\bbg-surface\b/');

    // Кружок номера НА КАДРЕ — на паре «затемнение + чернила поверх
    // фотографии», которая темой не переключается. `text-accent` в светлой
    // ветке становится тёмно-жёлтым и поверх фотографии даёт 1.4:1;
    // проверять его отсутствием по всей секции нельзя — надзаголовок
    // и акцентное слово заголовка стоят на нём законно.
    preg_match('/<div[^>]*\bbg-scrim\/85\b[^>]*>/', $section, $badge);

    expect($badge)->not->toBeEmpty('кружок номера на кадре не найден по `bg-scrim/85`')
        ->and($badge[0])->toContain('text-on-photo')
        ->and($badge[0])->not->toContain('text-accent');
});

it('keeps every step in the markup instead of feeding the track on the fly', function () {
    // Веха 4.12: лента — не слайдер с подгрузкой. Шесть `<article>` лежат
    // в ответе сервера, а `line-clamp` режет описание только визуально.
    // Это условие SEO: блок описывает услугу словами, и поисковик читает
    // именно разметку.
    //
    // Этот сторож — единственный из четырёх, который на прежней сетке
    // проходил бы зелёным, и это не недосмотр: он закрывает не переезд
    // на ленту, а следующий шаг, которым ленту захотят «улучшить»
    // подгрузкой карточек. Тогда он и покраснеет.
    Setting::set('home.steps', collect(range(1, 6))
        ->map(fn (int $i): array => [
            'number' => '0'.$i,
            'title' => 'Этап ленты '.$i,
            'text' => 'Описание этапа номер '.$i.'.',
        ])
        ->all());

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    foreach (range(1, 6) as $i) {
        expect($section)->toContain('Этап ленты '.$i)
            ->and($section)->toContain('Описание этапа номер '.$i.'.');
    }

    expect(substr_count($section, '<article'))->toBe(6);
});

it('leaves the steps track scrollable and flat without javascript', function () {
    // Два отказа сразу, и оба тихие.
    //
    // Первый: лента без `overflow-x-auto` обрезает всё, кроме первых
    // карточек, — содержимое есть в разметке, но добраться до него нечем.
    //
    // Второй: высоту обёртки-пина ставит СКРИПТ, и только он. Класс
    // с фиксированной высотой (`min-h-screen`, `h-[150vh]`) или статический
    // `style` дали бы без `app.js` пустое поле в полтора экрана посреди
    // главной, а дыра читается как поломка, а не как «не загрузился
    // слайдер».
    //
    // Утверждение адресное — про сам тег обёртки, а не про весь блок.
    // Прежняя редакция искала высоту от вьюпорта по всей секции и
    // покраснела, как только карточке законно понадобилась `h-[32vw]`.
    // Ослабить её значило бы потерять сторожа; сузить до обёртки —
    // сохранить, потому что «высота приходит из скрипта» это утверждение
    // ровно про неё.
    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап ленты', 'text' => 'Текст этапа.'],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    preg_match_all('/\sclass="([^"]*)"/', $section, $matches);

    expect(implode(' ', $matches[1]))->toContain('overflow-x-auto');

    $pin = pinWrapperTag($section);

    expect($pin)->toContain('x-bind:style=')
        ->and($pin)->not->toMatch('/\sclass="/')
        ->and($pin)->not->toMatch('/(?<!x-bind:)\sstyle="/');
});

it('holds the pin for a moment after the last step card has landed', function () {
    // Дефект 14.08.2026: прижатие отпускало страницу ровно в тот кадр,
    // когда доезжала последняя карточка. Посетитель листает карточки
    // колесом, по инерции делает ещё один щелчок — и доехавшая лента
    // отдаёт странице всю накопленную прокрутку сразу, унося три
    // следующих блока. Лечится задержкой: высота обёртки-пина складывается
    // из пути ленты И запаса под неё.
    //
    // Утверждение про выражение высоты, а не про число: высоту ставит
    // скрипт, и в разметке от неё есть только это выражение. Упрощение
    // обратно до одного `travel` — ровно возврат дефекта, и заметен он
    // будет не глазами на макете, а тем же «блоки пролетели мимо».
    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап ленты', 'text' => 'Текст этапа.'],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    expect(pinWrapperTag($section))->toContain('travel + hold');

    // Место, на которое лента доезжает за задержку, — настоящая коробка
    // в конце ленты: `scrollLeft` дальше своего максимума не идёт,
    // а концевые `padding` и `margin` попадают в прокручиваемую область
    // не во всех браузерах одинаково.
    //
    // По умолчанию она СКРЫТА, и это вторая половина утверждения: без
    // `app.js` задержки нет вовсе, а пустое поле в конце ленты было бы —
    // тот же класс отказа, что фиксированная высота на обёртке.
    preg_match('/<div\s+x-ref="tail"[^>]*>/', $section, $tail);

    expect($tail)->not->toBeEmpty('в ленте этапов нет хвоста `x-ref="tail"`')
        ->and($tail[0])->toMatch('/\sclass="[^"]*\bhidden\b/');
});

it('moves the folded stack together with the track during that hold', function () {
    // Сложенные карточки стоят на `left` и прокрутку ленты не замечают,
    // а открытая едет вместе с ней. Поэтому за задержку одной прокрутки
    // мало: без вычитания сдвига из `left` стопка останется на месте,
    // пока последняя карточка уезжает, — подписи отстанут от кадра,
    // который называют.
    //
    // Отказ тихий вдвойне: разметка цела, ошибок нет, а разъезжаются они
    // на 32 пикселя — на статичном снимке это читается как «так и было
    // задумано».
    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Первый этап', 'text' => 'Текст этапа.'],
        ['number' => '02', 'title' => 'Второй этап', 'text' => 'Текст этапа.'],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    expect($section)->toContain('var(--i)*var(--strip)_-_var(--shift)');

    // `--shift` объявлен на самой ленте, и это не запасное значение,
    // а условие валидности: `calc()` с неопределённой переменной браузер
    // выбрасывает целиком, то есть карточка теряет `left`, а с ним стопку.
    expect($section)->toContain('[--shift:0px]');
});

it('names the steps track for screen readers and reaches it from the keyboard', function () {
    // Прокручиваемая область обязана добираться с клавиатуры, а лента
    // без имени читается скринридером как безымянный контейнер: содержимое
    // он перечислит, а что это за набор карточек — не скажет.
    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап ленты', 'text' => 'Текст этапа.'],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    expect($section)->toMatch('/\srole="region"/')
        ->and($section)->toMatch('/\stabindex="0"/');

    preg_match('/\saria-label="([^"]*)"/', $section, $label);

    expect($label)->not->toBeEmpty('у ленты этапов нет `aria-label`')
        ->and(trim($label[1]))->not->toBe('');
});

it('hides the steps progress bar until alpine boots', function () {
    // Тот же приём и то же основание, что у стрелок фотогалереи вехи 4.3
    // и панели фильтра статусов вехи 4.6: полоса, застывшая на нуле, врёт
    // о положении в ленте, а без Alpine она застынет на нуле навсегда.
    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Этап ленты', 'text' => 'Текст этапа.'],
    ]);

    $section = processSectionHtml($this->get('/')->assertOk()->getContent());

    expect($section)->toContain('x-cloak');
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

it('adds a button for a car in transit when the selection has one', function () {
    // Кнопки строятся из статусов, ФАКТИЧЕСКИ встреченных в подборке,
    // а не из полного enum-а: кнопка без карточек — это пустая выдача
    // по клику.
    Car::factory()->onHomepage()->create(['status' => CarStatus::InStock]);
    Car::factory()->onHomepage()->inTransit()->create();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('Фильтр по статусу')
        ->and($html)->toContain("status = '".CarStatus::InTransit->value."'");
});

it('leaves the in transit button out when no such car is in the selection', function () {
    Car::factory()->onHomepage()->create(['status' => CarStatus::InStock]);
    Car::factory()->onHomepage()->create(['status' => CarStatus::OnOrder]);
    // В подборку не попадёт: отметки «на главной» нет.
    Car::factory()->inTransit()->create();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('Фильтр по статусу')
        ->and($html)->not->toContain("status = '".CarStatus::InTransit->value."'");
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
