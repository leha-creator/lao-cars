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
