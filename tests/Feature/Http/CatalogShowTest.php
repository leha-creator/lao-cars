<?php

use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarPhoto;

/*
 * Карточка автомобиля (веха 3.6).
 *
 * Сетка характеристик, группы и доступность проданной карточки — всё,
 * на что ляжет вёрстка вехи 4.3 и микроразметка Vehicle.
 */

it('serves a car card by its slug', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    $car = Car::factory()->for($brand)->create(['model' => '001', 'year' => 2024]);

    // Привязка по slug работает через #[RouteKey('slug')] на модели.
    expect($car->slug)->toBe('zeekr-001-2024');

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Zeekr')
        ->assertSee('001');
});

it('returns 404 for an unknown slug', function () {
    $this->get('/catalog/no-such-car')->assertNotFound();
});

it('still serves a sold car', function () {
    $car = Car::factory()->sold()->create();

    // «Продан» — не удаление: карточка остаётся ради истории и SEO,
    // и ссылки из выдачи Google не должны превращаться в 404.
    $this->get('/catalog/'.$car->slug)->assertOk();
});

it('renders the attribute grid with group headers', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inGroup('Кузов и салон')
        ->create(['key' => 'body_type', 'label' => 'Кузов', 'sort_order' => 10]);
    CarAttribute::factory()->boolean()->inGroup('Импорт')
        ->create(['key' => 'customs_cleared', 'label' => 'Растаможен', 'sort_order' => 20]);
    CarAttribute::factory()->text()
        ->create(['key' => 'trim', 'label' => 'Комплектация', 'sort_order' => 30]);

    $car = Car::factory()->create();
    $car->syncAttributeValues([
        'body_type' => 'Кроссовер',
        'customs_cleared' => true,
        'trim' => 'Long Range AWD',
    ]);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Кузов и салон')
        ->assertSee('Кроссовер')
        ->assertSee('Импорт')
        // Подпись булева значения приходит из CarAttribute::format().
        ->assertSee('Растаможен')
        ->assertSee('Да')
        // Характеристика без группы выводится блоком без заголовка.
        ->assertSee('Комплектация')
        ->assertSee('Long Range AWD');
});

it('hides an attribute that is not shown in the card', function () {
    CarAttribute::factory()->text()->hiddenInCard()
        ->create(['key' => 'internal_note', 'label' => 'Служебная заметка']);

    $car = Car::factory()->create();
    $car->syncAttributeValues(['internal_note' => 'Не для сайта']);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertDontSee('Не для сайта');
});

it('serves a car without a single attribute value', function () {
    CarAttribute::factory()->select(['Седан'])->create(['key' => 'body_type']);

    $car = Car::factory()->create();

    $this->get('/catalog/'.$car->slug)->assertOk();
});

it('serves a car without a price', function () {
    $car = Car::factory()->withoutPrice()->create();

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Цена по запросу');
});

/*
 * Вёрстка вехи 4.3. Галерея — главный кандидат на «упрощение»: свести её
 * к одному <img :src> и списку путей в x-data короче ровно настолько,
 * чтобы выглядеть улучшением. С работающим JS после такой правки галерея
 * ведёт себя одинаково, и заметить подмену можно только здесь.
 */

it('keeps every photo in the markup, not only the main one', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->count(3)->sequenced()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    // Стопка главного фото: все снимки лежат в разметке, показывается один.
    //
    // Якорь сменился вехой 4.14 с `absolute inset-0` на `cursor-zoom-in`,
    // и это не подгонка под новую разметку: позиционирование переехало
    // с `img` на обёртку `<a href>`, которая появилась ради просмотра
    // в полный размер без JS. `cursor-zoom-in` есть только у этих обёрток
    // — у миниатюр и у карточек похожих автомобилей его нет, — поэтому
    // считается ровно то же, что и раньше: сколько снимков реально лежит
    // в разметке главного кадра.
    preg_match_all('/<a[^>]*cursor-zoom-in[^>]*>/', $content, $stack);

    expect($stack[0])->toHaveCount(3);

    foreach ($car->photos as $photo) {
        expect($content)->toContain($photo->url);
    }
});

it('loads the main photo eagerly and the thumbnails lazily', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->count(3)->sequenced()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    // Обёртка `<a>` вместе с её `<img>`: с вехи 4.14 позиционирование
    // живёт на ссылке, а `fetchpriority`/`loading` — по-прежнему
    // на изображении, и проверять их надо на нём.
    preg_match_all('/<a[^>]*cursor-zoom-in[^>]*>\s*<img[^>]*>/s', $content, $stack);

    expect($stack[0])->toHaveCount(3);

    // Главное фото — LCP этой страницы (правило RULES.md). Рефлекс,
    // выработанный на карточках списка, откладывает загрузку главного
    // изображения страницы, и ни один автотест кроме этого не покажет:
    // локально файл грузится мгновенно.
    expect($stack[0][0])->toContain('fetchpriority="high"')
        ->and($stack[0][0])->not->toContain('loading="lazy"')
        // Остальные снимки до клика не нужны.
        ->and($stack[0][1])->toContain('loading="lazy"');
});

it('serves a car without photos and shows a caption instead of a gap', function () {
    $car = Car::factory()->create();

    // Пустой прямоугольник читается как недогруженная страница.
    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Фотографии готовятся');
});

it('does not render arrows and a counter for a single photo', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->for($car)->create();

    // Листать нечего, а стрелки, которые ничего не делают, — обман.
    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertDontSee('aria-label="Следующее фото"', escape: false)
        ->assertDontSee('x-text="(active + 1)', escape: false);
});

it('keeps a single h1 and puts similar cards under h3', function () {
    $brand = Brand::factory()->create();
    Car::factory()->count(config('catalog.similar_limit'))->for($brand)->create(['price' => 5_000_000]);

    $car = Car::factory()->for($brand)->create(['price' => 5_000_000]);

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    // Иерархия заголовков — не оформление: по ней ходят скринридеры
    // и по ней страницу разбирает поисковик.
    expect(preg_match_all('/<h1[\s>]/', $content))->toBe(1)
        ->and($content)->toContain('<h3');
});

it('takes meta tags from the car and falls back to the assembled ones', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);

    $car = Car::factory()->for($brand)->create([
        'model' => '001',
        'year' => 2024,
        'meta_title' => 'Свой заголовок карточки',
        'meta_description' => 'Своё описание карточки',
    ]);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('<title>Свой заголовок карточки</title>', escape: false)
        ->assertSee('content="Своё описание карточки"', escape: false);

    // Администратор очистил поля — карточка возвращается к сборке из полей,
    // а не отдаёт пустой тег.
    $car->update(['meta_title' => null, 'meta_description' => null]);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('<title>Zeekr 001, 2024 — '.config('app.name').'</title>', escape: false)
        ->assertSee('Купить Zeekr 001 2024 года', escape: false);
});

it('does not run a query per attribute row', function () {
    $brand = Brand::factory()->create();

    // Блок похожих должен заполняться у обеих карточек одинаково, иначе
    // разница в счётчике придёт от него, а не от сетки характеристик.
    Car::factory()->count(config('catalog.similar_limit'))->for($brand)->create(['price' => 5_000_000]);

    for ($i = 0; $i <= 9; $i++) {
        CarAttribute::factory()->text()->create(['key' => 'attr_'.$i, 'label' => 'Строка '.$i]);
    }

    $single = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    $single->syncAttributeValues(['attr_0' => 'значение']);

    $full = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    $values = [];

    for ($i = 1; $i <= 9; $i++) {
        $values['attr_'.$i] = 'значение '.$i;
    }

    $full->syncAttributeValues($values);

    // Иначе первый из двух замеров включит промах кеша настроек,
    // который платят шапка и подвал, и разница в единицу сойдёт за N+1.
    warmSettingsCache();

    $singleQueries = countQueries(fn () => $this->get('/catalog/'.$single->slug)->assertOk());
    $fullQueries = countQueries(fn () => $this->get('/catalog/'.$full->slug)->assertOk());

    // Без with('attributeValues.attribute') каждая строка сетки давала бы
    // свой запрос — об этом прямо предупреждает PHPDoc cardAttributes().
    expect($singleQueries)->toBeGreaterThan(0)
        ->and($fullQueries)->toBe($singleQueries);
});

/*
 * Кадр без обрезки и просмотр в полный размер (веха 4.14, пункт 2).
 */

it('fits the main frame instead of cropping it, and keeps thumbnails cropped', function () {
    $car = Car::factory()->create();
    CarPhoto::factory()->count(3)->sequenced()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    preg_match_all('/<a[^>]*cursor-zoom-in[^>]*>\s*<img[^>]*>/s', $content, $stack);

    // Фотография вписывается целиком: обрезка съедала у вертикальных
    // снимков верх и низ, о чём заказчик и написал.
    foreach ($stack[0] as $frame) {
        expect($frame)->toContain('object-contain');
    }

    // А миниатюры ОСТАЛИСЬ обрезанными, и это не забытая правка:
    // миниатюра — указатель на кадр, и вписанная в пятую часть ширины
    // она превращается в марку с полями на всю плитку.
    //
    // Отбор по обработчику переключения, а не по пути файла: `thumb_url`
    // откатывается на оригинал, когда превью не построилось, и якорь
    // по слову «thumbs» ловил бы то пусто, то густо.
    preg_match_all('/<a[^>]*x-on:click\.prevent="active = \d+"[^>]*>\s*<img[^>]*>/s', $content, $thumbs);

    expect($thumbs[0])->not->toBeEmpty();

    foreach ($thumbs[0] as $thumb) {
        expect($thumb)->toContain('object-cover');
    }
});

it('keeps one base 3:2 ratio on the main frame and the thumbnails', function () {
    // Заказчик, 21.08.2026: «большинство картинок будут 3:2, они должны
    // показываться полностью, без полей и без форматирования; поля —
    // если загружено фото другого формата».
    //
    // С `object-contain` ширину полей задаёт пропорция КОНТЕЙНЕРА:
    // у кадра в неё поля нулевые, у файла другого формата — видимые.
    // Так что «показать целиком» — это ровно про число в `aspect-*`,
    // и число проверяется, а не подразумевается.
    $car = Car::factory()->create();
    CarPhoto::factory()->count(3)->sequenced()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    preg_match('/<div class="relative mb-4 ([^"]*)"/', $content, $frame);

    expect($frame)->not->toBeEmpty()
        ->and($frame[1])->toContain('aspect-3/2')
        // Брейкпойнта быть не должно: 16:10 на телефоне и 16:9 на
        // компьютере означали, что «правильный формат» у одного файла
        // разный на разной ширине экрана, то есть без полей он не
        // показывался нигде.
        ->and($frame[1])->not->toMatch('/\b(?:sm|md|lg|xl|2xl):aspect-/');

    // Миниатюры держат ТУ ЖЕ пропорцию, хотя и остаются обрезанными:
    // разная пропорция у кадра и плитки означала бы, что один файл
    // показывается целиком в одном месте и режется в другом.
    preg_match_all('/<a[^>]*x-on:click\.prevent="active = \d+"[^>]*>/s', $content, $thumbs);

    expect($thumbs[0])->not->toBeEmpty();

    foreach ($thumbs[0] as $thumb) {
        expect($thumb)->toContain('aspect-3/2');
    }
});

it('wraps the main frame in a real link so it works without javascript', function () {
    // «Открыть в полном размере» обязано работать и без скрипта: клик
    // по кадру открывает оригинал штатным просмотрщиком браузера.
    // Сквозное правило проекта — фильтры каталога, форма заявки
    // и галерея деградируют, а не ломаются.
    $car = Car::factory()->create();
    $photo = CarPhoto::factory()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    expect($content)->toContain('href="'.$photo->url.'"')
        // Лайтбокс перехватывает клик, а не заменяет ссылку.
        ->and($content)->toContain('x-on:click.prevent="show(0, $event)"');
});

it('gives the main frame its real dimensions and omits them when unknown', function () {
    $car = Car::factory()->create();
    $known = CarPhoto::factory()->for($car)->create(['width' => 1600, 'height' => 900, 'sort_order' => 0]);
    CarPhoto::factory()->for($car)->create(['width' => null, 'height' => null, 'sort_order' => 1]);

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    preg_match_all('/<a[^>]*cursor-zoom-in[^>]*>\s*<img[^>]*>/s', $content, $stack);

    expect($stack[0][0])->toContain('width="1600"')
        ->and($stack[0][0])->toContain('height="900"')
        // У фотографии, залитой до вехи 4.14, размеров нет, пока по ней
        // не пройдёт `images:restamp`. Пустые атрибуты не пишутся вовсе:
        // `width="0"` схлопнул бы кадр, а место и так держит контейнер.
        ->and($stack[0][1])->not->toContain('width=')
        ->and($stack[0][1])->not->toContain('height=');
});

it('renders the lightbox once for the whole page, not per frame', function () {
    // Семь копий разметки — это семь мест, где надо не забыть про фокус
    // и Escape.
    $car = Car::factory()->create();
    CarPhoto::factory()->count(4)->sequenced()->for($car)->create();

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    // Считается ИМЕННО окно просмотра: `role="dialog"` есть и у модалки
    // формы заявки на этой же странице, и счёт по нему проверял бы
    // не то. `x-ref="dialog"` принадлежит только лайтбоксу.
    expect(substr_count($content, 'x-ref="dialog"'))->toBe(1)
        ->and(substr_count($content, 'x-data="photoLightbox('))->toBe(1)
        ->and($content)->toContain('aria-modal="true"')
        // Без `x-cloak` окно мелькает во всю страницу на каждой загрузке,
        // пока Alpine не отработал.
        ->and($content)->toMatch('/x-cloak[^>]*x-show="open"|x-show="open"[^>]*x-cloak/');
});
