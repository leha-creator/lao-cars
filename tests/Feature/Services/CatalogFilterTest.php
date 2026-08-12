<?php

use App\Enums\CarStatus;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Services\CatalogCriteria;
use App\Services\CatalogFilter;
use App\Support\AttributeFilterIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Фильтр каталога (веха 3.6).
 *
 * Проверяются три разных вещи, и путать их нельзя: что фильтр сужает
 * выдачу правильно, что порядок выдачи устойчив между страницами и что
 * условие по характеристикам сохраняет форму, при которой индекс вообще
 * применим. Последнее функциональными проверками не ловится — выдача
 * остаётся верной и без индекса, — поэтому сторожа стоят отдельно.
 */

/**
 * Запрос каталога, собранный фильтром из сырых GET-параметров.
 *
 * @param  array<string, mixed>  $params
 * @return Builder<Car>
 */
function catalogFilter(array $params = []): Builder
{
    return app(CatalogFilter::class)->apply(Car::query(), CatalogCriteria::fromArray($params));
}

it('narrows the results by brand', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    Car::factory()->count(2)->for($zeekr)->create();
    Car::factory()->create();

    expect(catalogFilter()->count())->toBe(3)
        ->and(catalogFilter(['brand' => $zeekr->slug])->count())->toBe(2);
});

it('returns nothing for a brand that does not exist', function () {
    Car::factory()->count(3)->create();

    // Молча снятый фильтр показал бы весь каталог там, где пользователь
    // ждёт сужения.
    expect(catalogFilter(['brand' => 'no-such-brand'])->count())->toBe(0);
});

it('narrows the results by engine type', function () {
    Car::factory()->count(2)->create(['engine_type' => EngineType::Electric]);
    Car::factory()->create(['engine_type' => EngineType::Petrol]);

    expect(catalogFilter(['engine' => 'electric'])->count())->toBe(2);
});

it('narrows the results by availability', function () {
    Car::factory()->count(2)->inStock()->create();
    Car::factory()->onOrder()->create();
    Car::factory()->count(3)->inTransit()->create();

    expect(catalogFilter(['status' => 'in_stock'])->count())->toBe(2)
        ->and(catalogFilter(['status' => 'on_order'])->count())->toBe(1)
        ->and(catalogFilter(['status' => 'in_transit'])->count())->toBe(3);
});

it('returns exactly the cars in transit and nothing else', function () {
    $inTransit = Car::factory()->count(2)->inTransit()->create();
    Car::factory()->inStock()->create();
    Car::factory()->onOrder()->create();
    Car::factory()->sold()->create();

    // Счётчика мало: фильтр, поймавший не те две карточки, дал бы
    // ту же двойку.
    expect(catalogFilter(['status' => 'in_transit'])->pluck('id')->sort()->values()->all())
        ->toBe($inTransit->pluck('id')->sort()->values()->all());
});

it('narrows the results by year and price range', function () {
    Car::factory()->create(['year' => 2020, 'price' => 1_000_000]);
    Car::factory()->create(['year' => 2024, 'price' => 5_000_000]);

    expect(catalogFilter(['year_from' => 2023])->count())->toBe(1)
        ->and(catalogFilter(['year_to' => 2021])->count())->toBe(1)
        ->and(catalogFilter(['price_from' => 2_000_000])->count())->toBe(1)
        ->and(catalogFilter(['price_to' => 2_000_000])->count())->toBe(1);
});

it('intersects two filters instead of joining them', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $voyah = Brand::factory()->create(['name' => 'Voyah']);

    Car::factory()->for($zeekr)->create(['engine_type' => EngineType::Electric]);
    Car::factory()->for($zeekr)->create(['engine_type' => EngineType::Petrol]);
    Car::factory()->for($voyah)->create(['engine_type' => EngineType::Electric]);

    // Объединение дало бы три, пересечение — одну.
    expect(catalogFilter(['brand' => $zeekr->slug, 'engine' => 'electric'])->count())->toBe(1);
});

it('never returns a sold car', function () {
    $brand = Brand::factory()->create();
    $sold = Car::factory()->for($brand)->sold()->create([
        'year' => 2024,
        'price' => 3_000_000,
        'engine_type' => EngineType::Electric,
    ]);
    Car::factory()->for($brand)->create();

    $parameterSets = [
        [],
        ['brand' => $brand->slug],
        ['status' => 'in_stock'],
        ['status' => 'on_order'],
        ['year_from' => 2024, 'year_to' => 2024],
        ['price_from' => 3_000_000, 'price_to' => 3_000_000],
        ['engine' => 'electric'],
        ['sort' => 'price_desc'],
    ];

    foreach ($parameterSets as $parameters) {
        expect(catalogFilter($parameters)->pluck('id')->all())
            ->not->toContain($sold->id);
    }
});

it('normalizes an inverted range instead of rejecting it', function () {
    Car::factory()->create(['year' => 2022, 'price' => 3_000_000]);
    Car::factory()->create(['year' => 2026, 'price' => 9_000_000]);

    // Две ошибки в двух селектах — обычное дело, и правильный ответ
    // на них выдача, а не пустой каталог.
    expect(catalogFilter(['year_from' => 2023, 'year_to' => 2021])->count())
        ->toBe(catalogFilter(['year_from' => 2021, 'year_to' => 2023])->count())
        ->toBe(1)
        ->and(catalogFilter(['price_from' => 4_000_000, 'price_to' => 2_000_000])->count())
        ->toBe(catalogFilter(['price_from' => 2_000_000, 'price_to' => 4_000_000])->count())
        ->toBe(1);
});

it('excludes a car without a price from a price range but not from the catalog', function () {
    $priced = Car::factory()->create(['price' => 3_000_000]);
    $onRequest = Car::factory()->withoutPrice()->create();

    expect(catalogFilter(['price_from' => 1])->pluck('id')->all())->toBe([$priced->id])
        ->and(catalogFilter(['price_to' => 100_000_000])->pluck('id')->all())->toBe([$priced->id])
        // «Цена по запросу» не принадлежит ни одному диапазону, но
        // из каталога никуда не девается.
        ->and(catalogFilter()->pluck('id')->all())->toContain($onRequest->id);
});

it('puts price on request last in both sort directions', function () {
    $brand = Brand::factory()->create();
    Car::factory()->for($brand)->create(['price' => 1_000_000]);
    Car::factory()->for($brand)->create(['price' => 5_000_000]);
    $onRequest = Car::factory()->for($brand)->withoutPrice()->create();

    // В PostgreSQL умолчание NULLS зависит от направления: без явного
    // NULLS LAST «сначала дорогие» открывались бы этой карточкой.
    expect(catalogFilter(['sort' => 'price_asc'])->pluck('id')->last())->toBe($onRequest->id)
        ->and(catalogFilter(['sort' => 'price_desc'])->pluck('id')->last())->toBe($onRequest->id);
});

it('sorts by price in the requested direction', function () {
    $brand = Brand::factory()->create();
    $cheap = Car::factory()->for($brand)->create(['price' => 1_000_000]);
    $expensive = Car::factory()->for($brand)->create(['price' => 5_000_000]);

    expect(catalogFilter(['sort' => 'price_asc'])->pluck('id')->all())->toBe([$cheap->id, $expensive->id])
        ->and(catalogFilter(['sort' => 'price_desc'])->pluck('id')->all())->toBe([$expensive->id, $cheap->id]);
});

it('keeps pagination stable when created_at values collide', function () {
    $brand = Brand::factory()->create();
    // Сид создаёт весь каталог в одну секунду, импорт партии карточек
    // одним прогоном даст то же самое.
    Car::factory()->count(10)->for($brand)->create(['created_at' => now()->subDay()]);

    $first = catalogFilter()->paginate(5, ['*'], 'page', 1)->pluck('id')->all();
    $second = catalogFilter()->paginate(5, ['*'], 'page', 2)->pluck('id')->all();
    $shown = array_merge($first, $second);

    // Без tie-breaker по id одна карточка попадает на обе страницы,
    // а другая не попадает никуда.
    expect($shown)->toHaveCount(10)
        ->and(array_unique($shown))->toHaveCount(10);
});

it('narrows the results by a dynamic attribute', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inFilter()
        ->create(['key' => 'body_type']);

    $crossover = Car::factory()->create();
    $crossover->syncAttributeValues(['body_type' => 'Кроссовер']);

    $sedan = Car::factory()->create();
    $sedan->syncAttributeValues(['body_type' => 'Седан']);

    Car::factory()->create();

    expect(catalogFilter(['attr' => ['body_type' => 'Кроссовер']])->pluck('id')->all())
        ->toBe([$crossover->id]);
});

it('joins two attribute filters with AND', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inFilter()->create(['key' => 'body_type']);
    CarAttribute::factory()->boolean()->inFilter()->create(['key' => 'customs_cleared']);

    $both = Car::factory()->create();
    $both->syncAttributeValues(['body_type' => 'Кроссовер', 'customs_cleared' => true]);

    $onlyBody = Car::factory()->create();
    $onlyBody->syncAttributeValues(['body_type' => 'Кроссовер', 'customs_cleared' => false]);

    expect(catalogFilter(['attr' => ['body_type' => 'Кроссовер', 'customs_cleared' => '1']])->pluck('id')->all())
        ->toBe([$both->id]);
});

it('returns nothing for a value outside the option list', function () {
    CarAttribute::factory()->select(['Седан'])->inFilter()->create(['key' => 'body_type']);

    $car = Car::factory()->create();
    $car->syncAttributeValues(['body_type' => 'Седан']);

    // Честный ноль, а не молча снятый фильтр: «нашлось 11» там, где
    // пользователь ждал сужения, хуже пустой выдачи.
    expect(catalogFilter(['attr' => ['body_type' => 'Лимузин']])->count())->toBe(0);
});

it('drops an attribute key that is not in the dictionary', function () {
    Car::factory()->count(2)->create();

    expect(catalogFilter(['attr' => ['no_such_key' => 'что угодно']])->count())->toBe(2);
});

it('drops an attribute that is not marked for the filter', function () {
    CarAttribute::factory()->select(['Седан'])->create(['key' => 'body_type', 'show_in_filter' => false]);

    $car = Car::factory()->create();
    $car->syncAttributeValues(['body_type' => 'Седан']);
    Car::factory()->create();

    // Фильтровать через URL по непомеченной характеристике нельзя —
    // это контракт, а не защита.
    expect(catalogFilter(['attr' => ['body_type' => 'Седан']])->count())->toBe(2);
});

it('warns about a filter flag on a non-filterable type and drops the parameter', function () {
    $attribute = CarAttribute::factory()->text()->create(['key' => 'trim']);

    // Противоречие в справочнике заводится в обход событий Eloquent —
    // ровно так оно и возникает: массовым обновлением или миграцией.
    // Через модель и фабрику собрать его нельзя, флаг гасится
    // на сохранении.
    DB::table('car_attributes')->where('id', $attribute->id)->update(['show_in_filter' => true]);

    Car::factory()->count(2)->create();

    Log::spy();

    expect(catalogFilter(['attr' => ['trim' => 'Long Range AWD']])->count())->toBe(2);

    Log::shouldHaveReceived('warning')->once();
});

it('keeps the prefix condition that makes the attribute index usable', function () {
    CarAttribute::factory()->select(['Седан'])->inFilter()->create(['key' => 'body_type']);

    $sql = catalogFilter(['attr' => ['body_type' => 'Седан']])->toSql();

    // Сведение условия к `value = ?` оставит выдачу верной и молча
    // выключит индекс — тот класс регрессии, который не ловится
    // функциональной проверкой. Убирать сторож нельзя.
    expect($sql)->toContain('left(value, '.AttributeFilterIndex::PREFIX_LENGTH.')')
        ->and($sql)->toContain('exists');
});

it('stores an attribute value longer than a b-tree entry', function () {
    CarAttribute::factory()->text()->create(['key' => 'trim']);

    $car = Car::factory()->create();
    $long = str_repeat('комплектация ', 154);
    $long = mb_substr($long, 0, 2000);

    $car->syncAttributeValues(['trim' => $long]);

    $stored = (string) $car->attributeValues()->value('value');

    // Доказательство, что индекс не покрывает колонку целиком: запись
    // b-tree ограничена 2704 байтами, а здесь их почти четыре тысячи.
    expect(mb_strlen($stored))->toBe(2000)
        ->and(strlen($stored))->toBeGreaterThan(2704);
});

it('keeps the prefix constant in sync with the migration', function () {
    $definition = (string) DB::table('pg_indexes')
        ->where('indexname', 'car_attribute_values_filter_index')
        ->value('indexdef');

    // PostgreSQL печатает имя функции в кавычках: "left"(value, 64).
    $normalized = str_replace('"', '', $definition);

    // Правка константы не пересоздаёт индекс: условие начнёт искать
    // по left(value, 128), индекс останется по 64, и запрос перестанет
    // его брать — молча. Менять длину можно только новой миграцией.
    expect($normalized)->toContain('left(value, ')
        ->and($normalized)->toContain('left(value, '.AttributeFilterIndex::PREFIX_LENGTH.')');
});

it('keeps the partial index predicate in sync with the available scope', function () {
    // Сторож на связку, которая уже один раз обманула читателя кода:
    // предикат трёх частичных индексов каталога обязан перечислять ровно
    // те статусы, которые перечисляет скоуп `Car::available()`.
    //
    // Расхождение не даёт ни ошибки, ни красного теста нигде больше:
    // запросы остаются корректными, просто перестают попадать в индекс,
    // и симптом появляется только на проде как «сайт тормозит».
    // Опасно оно в обе стороны — и расширенный скоуп при старом индексе,
    // и наоборот, — поэтому сравнение точное, а не «содержит».
    //
    // Список статусов берётся из самого скоупа, а не переписывается
    // константой рядом: копия разошлась бы с оригиналом ровно так же,
    // как разъезжаются индекс и запрос.
    $scopeStatuses = collect(Car::available()->toBase()->getBindings())
        ->map(fn (mixed $binding): string => $binding instanceof BackedEnum
            ? (string) $binding->value
            : (string) $binding)
        ->unique()->sort()->values()->all();

    expect($scopeStatuses)->not->toBeEmpty();

    foreach (['cars_available_created_index', 'cars_available_brand_index', 'cars_available_price_index'] as $name) {
        $definition = (string) DB::table('pg_indexes')
            ->where('indexname', $name)
            ->value('indexdef');

        expect($definition)->not->toBe('', "частичный индекс {$name} не существует");

        // Предикат печатается как `WHERE ((status)::text = ANY
        // ((ARRAY['in_stock'::character varying, …])::text[]))`.
        $predicate = (string) Str::after($definition, ' WHERE ');

        preg_match_all("/'([a-z_]+)'/", $predicate, $matches);

        $indexStatuses = collect($matches[1])->unique()->sort()->values()->all();

        expect($indexStatuses)->toBe(
            $scopeStatuses,
            "предикат {$name} разошёлся со списком статусов Car::available(): нужна новая миграция",
        );
    }
});

it('applies the available scope before any user condition', function () {
    Car::factory()->sold()->create(['status' => CarStatus::Sold]);
    Car::factory()->inStock()->create();
    Car::factory()->onOrder()->create();

    expect(catalogFilter()->count())->toBe(2);
});
