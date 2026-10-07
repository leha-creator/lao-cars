<?php

use App\Models\Media;
use App\Models\Setting;
use App\Support\Typography;
use Illuminate\Support\Facades\Log;

/*
 * Первый экран страницы запчастей и его показ на главной.
 *
 * Блок управляется одной настройкой `parts_page.hero` и живёт на двух
 * страницах. Почти каждый его отказ молчаливый: блок без заголовка, кнопка
 * без адреса и пропавший фон снаружи неотличимы от «так и настроено».
 * Поэтому сторожа здесь проверяют обе стороны каждой развилки — и то, что
 * блок появляется, и то, что без данных страница остаётся прежней.
 *
 * Значение настройки задаётся В ТЕСТЕ, а не берётся из сида: проверка сида
 * показала бы, что работает `SiteSettingSeeder`, а не что страница читает
 * настройку. Отсюда же главный сторож отката — `SectionPagesTest`: ключа
 * в его тестах нет, и он обязан проходить без единой правки.
 */

/*
 * Форму этот файл не отправляет, но секция заявки на обеих страницах
 * рендерится только при включённом приёме заявок, а сторож ниже проверяет,
 * что блок её не вытеснил.
 */
beforeEach(function (): void {
    enableLeadForms();
});

/**
 * Настройка блока целиком — с переопределением отдельных полей.
 *
 * @param  array<string, mixed>  $overrides
 */
function partsHero(array $overrides = []): void
{
    Setting::set('parts_page.hero', [
        'title' => 'Каталог проверочных запчастей',
        'text' => 'Проверочное описание первого экрана.',
        'button_text' => 'Открыть каталог',
        'button_url' => 'https://parts.example.com/catalog',
        'image_id' => null,
        'show_on_home' => false,
        ...$overrides,
    ]);
}

/**
 * Описание блока в том виде, в каком оно доходит до страницы.
 *
 * Описание — проза и проходит типографику: хвост склеивается неразрывными
 * пробелами, и искать на странице исходную строку бесполезно.
 */
function partsHeroText(): string
{
    return (string) Typography::tie('Проверочное описание первого экрана.');
}

/**
 * Разметка одной строкой: атрибуты тегов разнесены по строкам, и для
 * регулярок переносы схлопываются в пробел.
 */
function flatHtml(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', $html);
}

it('replaces the parts page heading with the hero and keeps a single h1', function () {
    Setting::set('parts_page.intro_title', 'Запасной заголовок запчастей');
    Setting::set('parts_page.intro_text', 'Запасное вступление запчастей.');
    partsHero();

    $response = $this->get('/parts')->assertOk();
    $html = $response->getContent();

    $response->assertSee('Каталог проверочных запчастей')
        ->assertSee(partsHeroText())
        // Заголовок вкладки браузера идёт за H1.
        ->assertSee('<title>Каталог проверочных запчастей — ', escape: false)
        // Запасные тексты при заполненном блоке не выводятся: второй
        // заголовок под первым экраном — это и есть второй H1.
        ->assertDontSee('Запасной заголовок запчастей')
        ->assertDontSee('Запасное вступление запчастей.');

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(flatHtml($html))->toMatch('/<h1[^>]*>\s*Каталог проверочных запчастей\s*<\/h1>/u');
});

it('falls back to the plain heading when the hero is not filled', function (mixed $value) {
    Setting::set('parts_page.intro_title', 'Запасной заголовок запчастей');
    Setting::set('parts_page.intro_text', 'Запасное вступление запчастей.');

    // `false` — «ключа нет вовсе»: так выглядит прод до первого сохранения
    // формы настроек, и страница там обязана остаться прежней.
    if ($value !== false) {
        Setting::set('parts_page.hero', $value);
    }

    $response = $this->get('/parts')->assertOk();
    $html = $response->getContent();

    $response->assertSee('Запасной заголовок запчастей')
        ->assertSee('Запасное вступление запчастей.')
        ->assertSee('<title>Запасной заголовок запчастей — ', escape: false);

    expect(substr_count($html, '<h1'))->toBe(1)
        // Шапка без фотографии под ней обязана остаться обычной: логотип
        // белый, и поверх светлой страницы он исчез бы.
        ->and($html)->toContain('sticky top-0')
        ->and($html)->not->toContain('-mt-[66px]');
})->with([
    'ключа нет' => [false],
    'значение null' => [null],
    'заголовок пуст' => [['title' => '', 'text' => 'Описание без заголовка.', 'show_on_home' => false]],
    'заголовок из пробелов' => [['title' => '   ', 'text' => null, 'show_on_home' => false]],
]);

it('switches the header to the overlay mode only under the hero', function () {
    partsHero();

    $html = $this->get('/parts')->assertOk()->getContent();

    preg_match('/<header\b[^>]*>/', $html, $header);

    // Шапка поверх фотографии не липкая и без подложки, а секция блока
    // подтянута под неё отрицательным отступом — оба признака обязаны
    // прийти вместе: одно без другого даёт либо полосу фона над кадром,
    // либо заголовок под меню.
    expect($header)->not->toBeEmpty()
        ->and($header[0])->toContain('from-page/92')
        ->and($header[0])->not->toContain('sticky')
        ->and($html)->toContain('-mt-[66px]')
        ->and($html)->toContain('lg:-mt-[76px]');
});

it('prints the hero button as an outside link opened in a new tab', function () {
    config(['app.url' => 'https://laocars.test']);
    partsHero();

    $html = flatHtml($this->get('/parts')->assertOk()->getContent());

    expect($html)->toMatch(
        '#<a\s[^>]*href="https://parts\.example\.com/catalog"[^>]*target="_blank"[^>]*rel="noopener noreferrer"[^>]*>\s*Открыть каталог\s*</a>#u',
    );
});

it('leaves a link to the site itself in the same tab', function () {
    config(['app.url' => 'https://laocars.test']);
    partsHero(['button_url' => 'https://laocars.test/catalog']);

    $html = flatHtml($this->get('/parts')->assertOk()->getContent());

    preg_match('#<a\s[^>]*href="https://laocars\.test/catalog"[^>]*>\s*Открыть каталог#u', $html, $link);

    expect($link)->not->toBeEmpty()
        ->and($link[0])->not->toContain('target=');
});

it('hides the hero button without a label or an address', function (array $overrides) {
    partsHero($overrides);

    // Кнопка с пустым `href` уводит на текущую страницу и при этом
    // выглядит рабочей. Блок без кнопки при этом остаётся на месте.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('Каталог проверочных запчастей')
        ->assertDontSee('Открыть каталог')
        ->assertDontSee('parts.example.com');
})->with([
    'нет подписи' => [['button_text' => '']],
    'нет адреса' => [['button_url' => null]],
    'адрес пуст' => [['button_url' => '']],
]);

it('drops a button whose address slipped past the settings form', function (string $url) {
    // Второй из двух рубежей. Форма такой адрес не принимает, но значение
    // может приехать мимо неё — сидом, миграцией, `psql`. Экранирование
    // Blade схему не трогает: `javascript:` в `href` его переживает.
    Log::spy();

    partsHero(['button_url' => $url]);

    $html = $this->get('/parts')->assertOk()->getContent();

    expect($html)->toContain('Каталог проверочных запчастей')
        ->and($html)->not->toContain('Открыть каталог')
        ->and($html)->not->toContain('alert(1)');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'адрес кнопки первого экрана отклонён')
            && $context['setting'] === 'parts_page.hero.button_url'
            // Сам адрес в лог не идёт.
            && ! str_contains(json_encode($context), 'alert'))
        ->once();
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,alert(1)'],
    'относительный путь' => ['/alert(1)'],
]);

it('loads the hero background as the page lcp image', function () {
    $media = Media::factory()->create();

    partsHero(['image_id' => $media->getKey()]);

    $html = flatHtml($this->get('/parts')->assertOk()->getContent());

    preg_match('#<img[^>]*'.preg_quote($media->url, '#').'[^>]*>#', $html, $img);

    // Фон первого экрана — LCP-элемент страницы (правило `RULES.md`):
    // рефлекс `loading="lazy"`, выработанный на карточках, откладывает
    // загрузку главного изображения, и ни один другой тест этого не покажет.
    expect($img)->not->toBeEmpty('фон первого экрана не найден в разметке')
        ->and($img[0])->toContain('fetchpriority="high"')
        ->and($img[0])->not->toContain('loading=')
        // Дескриптор без настоящих ширин заставил бы браузер выбрать
        // не тот файл — у записи медиабиблиотеки ширина одна.
        ->and($img[0])->not->toContain('srcset');
});

it('keeps the hero up when its background was deleted from the library', function () {
    // Штатным путём сюда не попасть — удаление используемой записи
    // блокирует `Media::usages()`. Снаружи отказ неотличим от пустого
    // поля: блок просто остаётся на тёмной заливке.
    Log::spy();

    $media = Media::factory()->create();
    $id = $media->getKey();
    $url = $media->url;
    $media->forceDelete();

    partsHero(['image_id' => $id]);

    $html = $this->get('/parts')->assertOk()->getContent();

    expect($html)->toContain('Каталог проверочных запчастей')
        ->and($html)->not->toContain($url)
        ->and($html)->not->toContain('fetchpriority="high"');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'фон первого экрана')
            && $context['setting'] === 'parts_page.hero.image_id'
            && $context['media_id'] === $id)
        ->once();
});

it('keeps the parts request form and its anchor under the hero', function () {
    partsHero();

    $html = $this->get('/parts')->assertOk()->getContent();

    // Блок заменил заголовок, а не страницу: форма подбора с полями
    // автомобиля и якорь, на который ведёт кнопка в шапке, на месте.
    expect(substr_count($html, 'id="lead-form"'))->toBe(1)
        ->and($html)->toContain('name="part_vin"');
});

it('does not leak blade comments into the pages with the hero', function (string $uri) {
    // Комментарий компонента длинный, и внутри него легко написать его же
    // закрывающую последовательность — остаток тогда уезжает в разметку
    // текстом при ответе 200 (правило `RULES.md`).
    partsHero(['show_on_home' => true]);

    $html = $this->get($uri)->assertOk()->getContent();

    expect($html)->toContain('Каталог проверочных запчастей')
        ->and($html)->not->toContain('{{--')
        ->and($html)->not->toContain('--}}');
})->with(['/parts', '/']);

it('shows the hero on the homepage only when switched on', function () {
    partsHero(['show_on_home' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Каталог проверочных запчастей')
        ->assertDontSee('id="parts-catalog"', escape: false);

    partsHero(['show_on_home' => true]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Каталог проверочных запчастей')
        ->assertSee(partsHeroText())
        ->assertSee('Открыть каталог')
        ->assertSee('id="parts-catalog"', escape: false);
});

it('does not switch the homepage block on by a truthy non-boolean', function (mixed $flag) {
    // Значение приходит из jsonb, и приведение к булеву включило бы показ
    // по строке — в том числе по строке «false».
    partsHero(['show_on_home' => $flag]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Каталог проверочных запчастей');
})->with([
    'строка false' => ['false'],
    'единица' => [1],
    'строка 1' => ['1'],
    'null' => [null],
]);

it('warns when the homepage switch is on while the hero has no title', function () {
    // Противоречие в настройках, а не выключенный блок: администратор
    // включил показ того, чего нет, и снаружи это ничем не отличается
    // от выключенного переключателя.
    Log::spy();

    partsHero(['title' => '', 'show_on_home' => true]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('id="parts-catalog"', escape: false)
        ->assertDontSee(partsHeroText());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'показ первого экрана на главной включён, а заголовок пуст'))
        ->once();
});

it('places the homepage block between the ecosystem and the services wrapper', function () {
    partsHero(['show_on_home' => true]);

    $html = $this->get('/')->assertOk()->getContent();

    $ecosystem = strpos($html, '<section id="service"');
    $block = strpos($html, 'id="parts-catalog"');
    // Общая Alpine-обёртка витрины услуг и формы заявки: блок обязан
    // стоять ДО неё, а не внутри.
    $wrapper = strpos($html, 'pick(id)');

    expect($ecosystem)->not->toBeFalse()
        ->and($block)->not->toBeFalse()
        ->and($wrapper)->not->toBeFalse()
        ->and($block)->toBeGreaterThan($ecosystem)
        ->and($block)->toBeLessThan($wrapper);
});

it('gives the homepage block an h2 and a lazy background', function () {
    $media = Media::factory()->create();

    partsHero(['show_on_home' => true, 'image_id' => $media->getKey()]);

    $html = flatHtml($this->get('/')->assertOk()->getContent());

    // H1 главной — у хиро, и он один. Блок запчастей посреди страницы
    // вторым H1 быть не может.
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('/<h2[^>]*>\s*Каталог проверочных запчастей\s*<\/h2>/u')
        // Подтяжка под шапку — только для первого экрана страницы запчастей.
        ->and($html)->not->toContain('lg:-mt-[76px] lg:min-h-[560px]');

    preg_match('#<img[^>]*'.preg_quote($media->url, '#').'[^>]*>#', $html, $img);

    // Блок стоит глубоко ниже первого экрана: здесь наоборот — ленивая
    // загрузка, иначе кадр конкурирует за канал с фоном хиро.
    expect($img)->not->toBeEmpty('фон блока не найден в разметке главной')
        ->and($img[0])->toContain('loading="lazy"')
        ->and($img[0])->not->toContain('fetchpriority');
});

it('costs the homepage exactly one query for the block background', function () {
    warmSettingsCache();

    $media = Media::factory()->create();

    partsHero(['show_on_home' => false, 'image_id' => null]);

    $without = countQueries(fn () => $this->get('/')->assertOk()->assertDontSee('Каталог проверочных запчастей'));

    partsHero(['show_on_home' => true, 'image_id' => $media->getKey()]);

    $with = countQueries(fn () => $this->get('/')->assertOk()->assertSee('Каталог проверочных запчастей'));

    // Нижняя граница обязательна — правило `RULES.md`: выборка,
    // не поймавшая ни одного запроса, иначе проходит вхолостую.
    expect($without)->toBeGreaterThan(0)
        ->and($with)->toBe($without + 1);
});
