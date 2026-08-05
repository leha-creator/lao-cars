<?php

use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarPhoto;

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

    // Список допустимых значений — два, а не три: ?status=sold
    // отбрасывается валидацией, а не открывает проданные.
    $this->get('/catalog?status=sold')->assertRedirect(route('catalog.index'));
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

it('does not run a query per card', function () {
    $brand = Brand::factory()->create();
    CarPhoto::factory()->for(Car::factory()->for($brand))->create();

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
