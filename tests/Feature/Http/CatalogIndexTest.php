<?php

use App\Enums\CarStatus;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarPhoto;
use Illuminate\Support\Facades\Log;

/*
 * Страница списка каталога (веха 3.6).
 *
 * Первый HTTP-тест проекта, и он же образец для вех 3.7 и 4.x: проверяется
 * не только код ответа, но и договорённости, которые ломаются молча, —
 * что фильтр переживает пагинацию, что комбинация фильтров не уходит
 * в индекс и что список не разваливается в N+1.
 */

it('serves the catalog and hides sold cars', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    Car::factory()->for($brand)->create(['model' => '001']);
    Car::factory()->for($brand)->sold()->create(['model' => 'Monjaro']);

    $this->get('/catalog')
        ->assertOk()
        ->assertSee('Zeekr')
        ->assertSee('001')
        ->assertDontSee('Monjaro');
});

it('applies a filter from the query string', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $voyah = Brand::factory()->create(['name' => 'Voyah']);

    Car::factory()->for($zeekr)->create(['model' => '001']);
    Car::factory()->for($voyah)->create(['model' => 'Free']);

    $response = $this->get('/catalog?brand='.$zeekr->slug);

    $response->assertOk();

    expect($response->viewData('cars')->pluck('model')->all())->toBe(['001']);
});

it('keeps the filter in pagination links', function () {
    $brand = Brand::factory()->create();
    Car::factory()->count(config('catalog.per_page') + 2)->for($brand)->create();

    // Без withQueryString() вторая страница открывается уже без фильтра,
    // и пользователь видит чужую выдачу по своей же ссылке.
    $this->get('/catalog?sort=price_asc')
        ->assertOk()
        ->assertSee('sort=price_asc&amp;page=2', escape: false);
});

it('shows exactly one page of cars and the remainder on the next', function () {
    $perPage = (int) config('catalog.per_page');
    Car::factory()->count($perPage + 2)->for(Brand::factory())->create();

    expect($this->get('/catalog')->viewData('cars')->count())->toBe($perPage)
        ->and($this->get('/catalog?page=2')->viewData('cars')->count())->toBe(2);
});

it('returns 404 for a page beyond the last one', function () {
    Car::factory()->create();

    // Пустая страница с кодом 200 — тонкий контент, который поисковик
    // увидит и запомнит.
    $this->get('/catalog?page=99')->assertNotFound();
});

it('serves an empty catalog with 200 even on a page beyond the last', function () {
    // На пустом каталоге пусто по существу, а не из-за номера страницы:
    // 404 здесь означал бы, что каталога не существует.
    $this->get('/catalog?page=5')->assertOk();
});

it('redirects an invalid parameter to the clean catalog', function () {
    Car::factory()->create();

    // Штатный редирект «назад» для публичной GET-страницы часто ведёт
    // на «/» — битая ссылка обязана открывать каталог, а не главную.
    $this->get('/catalog?brand=zzz')->assertRedirect(route('catalog.index'));
    $this->get('/catalog?year_from=1800')->assertRedirect(route('catalog.index'));
    $this->get('/catalog?sort=cheapest')->assertRedirect(route('catalog.index'));
});

it('does not let a sold status through the filter', function () {
    Car::factory()->sold()->create();

    // Список допустимых значений — три, а не четыре: ?status=sold
    // отбрасывается валидацией, а не открывает проданные. Расширение
    // списка новым статусом это ограничение не снимает.
    $this->get('/catalog?status=sold')->assertRedirect(route('catalog.index'));
});

it('logs a discarded status but stays quiet on a valid one', function () {
    Car::factory()->inTransit()->create();

    // Через `Log::listen`, а не через `Log::spy()`: за один запрос
    // в отладочный канал пишет не только `failedValidation`, и даже
    // префикса `[CatalogFilter]` мало — успешный запрос пишет им же
    // «фильтр применён». Проверять надо конкретную запись, иначе тест
    // либо ловит чужую, либо не ловит ничего.
    $discarded = [];

    Log::listen(function ($event) use (&$discarded): void {
        if (str_contains($event->message, 'параметры отброшены')) {
            $discarded[] = $event->level;
        }
    });

    // Мусорный статус по-прежнему отбрасывается и пишется в лог.
    $this->get('/catalog?status=teleporting')->assertRedirect(route('catalog.index'));

    // Уровень именно DEBUG: основной источник таких запросов — сканеры,
    // и WARN забил бы лог.
    expect($discarded)->toBe(['debug']);

    $discarded = [];

    // А новый статус валиден, и записи о нём быть не должно: расширение
    // списка не должно превратить рабочий фильтр в источник шума.
    $this->get('/catalog?status=in_transit')->assertOk();

    expect($discarded)->toBe([]);
});

it('shows a switcher button for every filterable status', function () {
    Car::factory()->create();

    $content = $this->get('/catalog')->assertOk()->getContent();

    // Четыре кнопки: «Все» плюс три статуса. «Продан» кнопки не получает —
    // он и валидацию не прошёл бы.
    foreach ([CarStatus::InStock, CarStatus::OnOrder, CarStatus::InTransit] as $status) {
        expect($content)->toContain('value="'.$status->value.'"')
            ->and($content)->toContain($status->label());
    }

    expect($content)->not->toContain('value="'.CarStatus::Sold->value.'"');
});

it('serves the cars in transit under their own status filter', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);

    Car::factory()->for($brand)->inTransit()->create(['model' => 'X']);
    Car::factory()->for($brand)->inStock()->create(['model' => '001']);

    $this->get('/catalog?status=in_transit')
        ->assertOk()
        ->assertSee('X')
        // Старый статус обязан выпасть: иначе вкладка «В пути» показывает
        // весь каталог, и человек решает, что фильтр не работает.
        ->assertDontSee('001');
});

it('keeps the old status links working', function () {
    // Контракт GET-параметров расширяется, а не ломается: ссылка,
    // сохранённая до появления «В пути», обязана открываться тем же.
    $brand = Brand::factory()->create(['name' => 'Voyah']);

    Car::factory()->for($brand)->inStock()->create(['model' => 'Free']);
    Car::factory()->for($brand)->inTransit()->create(['model' => 'Dream']);

    $this->get('/catalog?status=in_stock')
        ->assertOk()
        ->assertSee('Free')
        ->assertDontSee('Dream');

    $this->get('/catalog?status=on_order')->assertOk();
});

it('passes a cyrillic attribute value from the url to the filter', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inFilter()->create(['key' => 'body_type']);

    $crossover = Car::factory()->create(['model' => 'Monjaro']);
    $crossover->syncAttributeValues(['body_type' => 'Кроссовер']);

    $sedan = Car::factory()->create(['model' => 'Han']);
    $sedan->syncAttributeValues(['body_type' => 'Седан']);

    $response = $this->get('/catalog?'.http_build_query(['attr' => ['body_type' => 'Кроссовер']]));

    $response->assertOk();

    expect($response->viewData('cars')->pluck('model')->all())->toBe(['Monjaro']);
});

it('marks a filtered page as noindex and keeps follow', function () {
    Car::factory()->create();

    // follow обязателен: ссылки на карточки со страницы должны
    // обходиться, иначе карточки не попадут в индекс никогда.
    $this->get('/catalog?engine='.EngineType::Petrol->value)
        ->assertSee('name="robots" content="noindex,follow"', escape: false);

    $this->get('/catalog?sort=price_asc')
        ->assertSee('name="robots" content="noindex,follow"', escape: false);
});

it('leaves the clean catalog and its pages indexable', function () {
    Car::factory()->count(config('catalog.per_page') + 1)->for(Brand::factory())->create();

    // Умолчательная сортировка — тот же каталог, уводить его в noindex
    // не за что. Страница 2 обязана индексироваться, иначе её карточки
    // не попадут в индекс.
    $this->get('/catalog')->assertDontSee('name="robots"', escape: false);
    $this->get('/catalog?sort=new')->assertDontSee('name="robots"', escape: false);
    $this->get('/catalog?page=2')->assertDontSee('name="robots"', escape: false);
});

it('keeps filter parameters out of the canonical url but keeps the page', function () {
    Car::factory()->count(config('catalog.per_page') + 1)->for(Brand::factory())->create();

    $this->get('/catalog?sort=price_asc')
        ->assertSee('<link rel="canonical" href="'.route('catalog.index').'">', escape: false);

    $this->get('/catalog?page=2')
        ->assertSee('<link rel="canonical" href="'.route('catalog.index', ['page' => 2]).'">', escape: false);
});

/*
 * Вёрстка вехи 4.3. Тесты ниже сторожат не внешний вид, а договорённости,
 * которые ломаются молча: панель, предлагающая вариант без единого
 * автомобиля; форма, забывающая выбранное; скрытое поле `page`, уводящее
 * пользователя в 404.
 */

it('offers only brands that have an available car', function () {
    $withCars = Brand::factory()->create(['name' => 'Zeekr']);
    $withoutCars = Brand::factory()->create(['name' => 'Voyah']);

    Car::factory()->for($withCars)->create();

    // Вариант, дающий ноль автомобилей, — не «пустая выдача», а сломанное
    // доверие к фильтру. Проверяется значение опции, а не название марки:
    // название могло бы попасть в разметку карточки и увести тест.
    $this->get('/catalog')
        ->assertOk()
        ->assertSee('value="'.$withCars->slug.'"', escape: false)
        ->assertDontSee('value="'.$withoutCars->slug.'"', escape: false);
});

it('returns the selected filter values to the form', function () {
    $brand = Brand::factory()->create();
    Car::factory()->for($brand)->create(['status' => CarStatus::OnOrder]);

    $content = $this->get('/catalog?brand='.$brand->slug.'&status=on_order')
        ->assertOk()
        ->getContent();

    // Проверяется `selected`/`checked` рядом с нужным значением, а не факт
    // наличия строки: вариант в списке есть всегда, и без этой связки тест
    // прошёл бы и на форме, которая ничего не запоминает.
    expect($content)->toMatch('/value="'.preg_quote($brand->slug, '/').'"[^>]*\bselected\b/')
        ->and($content)->toMatch('/value="on_order"[^>]*\bchecked\b/');
});

it('keeps the page parameter out of the filter form', function () {
    Car::factory()->count(config('catalog.per_page') + 2)->for(Brand::factory())->create();

    // Форма на странице одна — фильтр каталога; ни шапка, ни подвал форм
    // не содержат.
    $form = str($this->get('/catalog?page=2')->assertOk()->getContent())
        ->after('<form')
        ->before('</form>')
        ->toString();

    // Скрытое поле `page` выглядит безобидной правкой «чтобы не терять
    // страницу» и ломает не фильтрацию, а пользователя: контроллер отдаёт
    // 404 за последней страницей, и фильтрация со страницы 3 привела бы
    // на ошибку вместо первой страницы новой выдачи.
    expect($form)->not->toContain('name="page"');
});

it('shows a reset block instead of a bare line on an empty result', function () {
    $brand = Brand::factory()->create();
    Car::factory()->for($brand)->create(['price' => 5_000_000]);

    // Пустая выдача — тупик, и выход из него должен быть виден.
    $this->get('/catalog?brand='.$brand->slug.'&price_to=1')
        ->assertOk()
        ->assertSee('По этим условиям ничего не нашлось')
        ->assertSee('Сбросить фильтры');
});

it('renders pagination with the catalog view', function () {
    Car::factory()->count(config('catalog.per_page') * 2 + 1)->for(Brand::factory())->create();

    $this->get('/catalog?page=2')
        ->assertOk()
        // Подсказка роботу о порядке страниц выдачи.
        ->assertSee('rel="prev"', escape: false)
        ->assertSee('rel="next"', escape: false)
        // Активная страница объявляется скринридером, а ссылки подписаны
        // словом, а не голой цифрой.
        ->assertSee('aria-current="page"', escape: false)
        ->assertSee('aria-label="Страница 1"', escape: false)
        // Вендорный вид светлый: его классы в ответе означали бы, что
        // `links()` снова рендерит `tailwind.blade.php`, а вместе с ним
        // в сборку вернётся палитра по умолчанию.
        ->assertDontSee('text-gray-', escape: false);
});

it('does not run a query per card', function () {
    $brand = Brand::factory()->create();
    CarPhoto::factory()->for(Car::factory()->for($brand))->create();

    // Иначе первый из двух замеров включит промах кеша настроек,
    // который платят шапка и подвал, и разница в единицу сойдёт за N+1.
    warmSettingsCache();

    $single = countQueries(fn () => $this->get('/catalog')->assertOk());

    Car::factory()->count(30)->for($brand)->create()
        ->each(fn (Car $car) => CarPhoto::factory()->for($car)->create());

    $full = countQueries(fn () => $this->get('/catalog')->assertOk());

    // Нижняя граница обязательна (правило RULES.md): без неё счётчик,
    // не поймавший ни одного запроса, проходит вхолостую.
    expect($single)->toBeGreaterThan(0)
        // Список из девяти карточек с фото и марками стоит ровно столько
        // же запросов, сколько список из одной. Расхождение — это N+1.
        ->and($full)->toBe($single);
});
