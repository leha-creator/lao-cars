<?php

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use App\Models\CarPhoto;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Стартовый набор характеристик для тестов сетки карточки: три группы,
 * порядок групп задаётся минимальным sort_order внутри группы.
 *
 * @return array<string, CarAttribute>
 */
function carAttributeFixtures(): array
{
    return [
        'body_type' => CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inGroup('Кузов и салон')
            ->create(['key' => 'body_type', 'label' => 'Кузов', 'sort_order' => 20]),
        'clearance' => CarAttribute::factory()->number('мм')->inGroup('Кузов и салон')
            ->create(['key' => 'clearance', 'label' => 'Клиренс', 'sort_order' => 30]),
        'customs_cleared' => CarAttribute::factory()->boolean()->inGroup('Импорт')
            ->create(['key' => 'customs_cleared', 'label' => 'Растаможен', 'sort_order' => 10]),
        // Без группы — должна оказаться последней, каким бы малым
        // ни был её sort_order.
        'trim' => CarAttribute::factory()->text()
            ->create(['key' => 'trim', 'label' => 'Комплектация', 'sort_order' => 5]),
        'internal_note' => CarAttribute::factory()->text()->hiddenInCard()
            ->create(['key' => 'internal_note', 'label' => 'Заметка', 'sort_order' => 1]),
    ];
}

it('belongs to a brand', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    $car = Car::factory()->for($brand)->create();

    expect($car->brand->name)->toBe('Zeekr')
        ->and($brand->cars)->toHaveCount(1);
});

it('casts status, engine type and drive to enums', function () {
    $car = Car::factory()->create([
        'status' => CarStatus::OnOrder,
        'engine_type' => EngineType::Hybrid,
        'drive' => DriveType::Full,
        'show_on_homepage' => true,
    ]);

    $car->refresh();

    expect($car->status)->toBe(CarStatus::OnOrder)
        ->and($car->engine_type)->toBe(EngineType::Hybrid)
        ->and($car->drive)->toBe(DriveType::Full)
        ->and($car->show_on_homepage)->toBeTrue();
});

it('generates a slug from brand, model and year', function () {
    $brand = Brand::factory()->create(['name' => 'Voyah']);

    $car = Car::factory()->for($brand)->create(['model' => 'Free', 'year' => 2023]);

    expect($car->slug)->toBe('voyah-free-2023');
});

it('appends a numeric suffix when the slug is taken', function () {
    $brand = Brand::factory()->create(['name' => 'Voyah']);

    // Одинаковые марка, модель и год в каталоге — обычное дело:
    // это разные комплектации одного автомобиля.
    $first = Car::factory()->for($brand)->create(['model' => 'Free', 'year' => 2023]);
    $second = Car::factory()->for($brand)->create(['model' => 'Free', 'year' => 2023]);

    expect($first->slug)->toBe('voyah-free-2023')
        ->and($second->slug)->toBe('voyah-free-2023-2');
});

it('keeps an explicitly provided slug', function () {
    $car = Car::factory()->create(['slug' => 'moi-sobstvennyi-slug']);

    expect($car->slug)->toBe('moi-sobstvennyi-slug');
});

it('resolves route bindings by slug', function () {
    $car = Car::factory()->create();

    expect($car->getRouteKeyName())->toBe('slug');
});

it('returns photos ordered by sort order', function () {
    $car = Car::factory()->create();

    // Вставка идёт вразнобой: порядок должен задаваться sort_order,
    // а не тем, как строки легли в таблицу.
    foreach ([2, 0, 1] as $order) {
        CarPhoto::factory()->for($car)->create(['sort_order' => $order]);
    }

    expect($car->photos->pluck('sort_order')->all())->toBe([0, 1, 2]);
});

it('treats the first photo by sort order as the main one', function () {
    $car = Car::factory()->create();

    CarPhoto::factory()->for($car)->create(['sort_order' => 5, 'path' => 'cars/last.jpg']);
    CarPhoto::factory()->for($car)->create(['sort_order' => 1, 'path' => 'cars/first.jpg']);

    expect($car->mainPhoto->path)->toBe('cars/first.jpg');
});

it('deletes photos together with the car', function () {
    $car = Car::factory()->has(CarPhoto::factory()->count(3), 'photos')->create();

    $car->delete();

    expect(CarPhoto::query()->count())->toBe(0);
});

it('filters the catalog by status', function () {
    Car::factory()->count(2)->inStock()->create();
    Car::factory()->onOrder()->create();
    Car::factory()->sold()->create();

    expect(Car::inStock()->count())->toBe(2)
        ->and(Car::onOrder()->count())->toBe(1)
        // Проданные остаются в базе ради истории и SEO,
        // но из выдачи каталога уходят.
        ->and(Car::available()->count())->toBe(3);
});

it('selects only cars marked for the homepage', function () {
    Car::factory()->count(2)->onHomepage()->create();
    Car::factory()->count(3)->create();

    expect(Car::onHomepage()->count())->toBe(2);
});

it('drops mileage for cars available on order', function () {
    $car = Car::factory()->onOrder()->create();

    expect($car->mileage)->toBeNull();
});

it('allows a null price meaning "on request"', function () {
    $car = Car::factory()->withoutPrice()->create();

    expect($car->price)->toBeNull();
});

it('collects leads submitted from its card', function () {
    $car = Car::factory()->create();

    Lead::factory()->count(2)->forCar($car)->create();
    Lead::factory()->general()->create();

    expect($car->leads)->toHaveCount(2);
});

it('groups card attributes by group, ungrouped last', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues([
        'body_type' => 'Седан',
        'clearance' => 190,
        'customs_cleared' => true,
        'trim' => 'Люкс',
    ]);

    $groups = $car->cardAttributes();

    // «Импорт» впереди «Кузова и салона» по минимальному sort_order
    // внутри группы (10 против 20). Безымянная группа — последней:
    // настоящий null ключом быть не может, PHP приводит его к ''.
    expect($groups->keys()->all())->toBe(['Импорт', 'Кузов и салон', ''])
        ->and($groups->get('Кузов и салон')->pluck('attribute.label')->all())
            ->toBe(['Кузов', 'Клиренс'])
        ->and($groups->get('')->pluck('attribute.label')->all())->toBe(['Комплектация']);
});

it('leaves attributes hidden from the card out of the grid', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues(['body_type' => 'Седан', 'internal_note' => 'служебное']);

    expect($car->cardAttributes()->flatten()->pluck('attribute.key')->all())
        ->toBe(['body_type']);
});

it('returns an empty grid for a car without attributes', function () {
    carAttributeFixtures();

    expect(Car::factory()->create()->cardAttributes())->toBeEmpty();
});

it('reads a typed attribute value by its machine key', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues(['clearance' => 190, 'customs_cleared' => false]);

    expect($car->attributeValue('clearance'))->toBe(190)
        ->and($car->attributeValue('customs_cleared'))->toBeFalse()
        ->and($car->attributeValue('unknown_key'))->toBeNull();
});

it('creates, updates and deletes attribute values in one call', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues(['body_type' => 'Седан', 'trim' => 'Люкс']);
    expect(CarAttributeValue::query()->count())->toBe(2);

    $car->syncAttributeValues(['body_type' => 'Кроссовер']);
    expect($car->attributeValue('body_type'))->toBe('Кроссовер')
        ->and(CarAttributeValue::query()->count())->toBe(2);

    // Администратор очистил поле — характеристика уходит из карточки,
    // а не остаётся пустой строкой.
    $car->syncAttributeValues(['trim' => '']);
    expect(CarAttributeValue::query()->count())->toBe(1)
        ->and($car->attributeValue('trim'))->toBeNull();
});

it('ignores keys missing from the reference book', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues(['body_type' => 'Седан', 'nope' => 'мусор']);

    expect(CarAttributeValue::query()->count())->toBe(1);
});

it('stores boolean false as "0" instead of deleting it', function () {
    // Тест на строгую проверку пустоты: с empty() он падает, потому что
    // empty('0') истинно — «Растаможен: Нет» удалялось бы вместо
    // сохранения, и фильтр 3.6 не нашёл бы ни одного нерастаможенного.
    carAttributeFixtures();
    $car = Car::factory()->create();

    $car->syncAttributeValues(['customs_cleared' => false]);

    $value = CarAttributeValue::query()->firstOrFail();

    expect($value->value)->toBe('0')
        ->and($value->formatted)->toBe('Нет')
        ->and($car->attributeValue('customs_cleared'))->toBeFalse();
});

it('refuses a select value outside the option list', function () {
    carAttributeFixtures();
    $car = Car::factory()->create();

    // Латинская «e» в «Кроссовeр».
    $car->syncAttributeValues(['body_type' => 'Кроссовeр']);

    expect(CarAttributeValue::query()->count())->toBe(0);
});

it('reads the reference book once per sync call', function () {
    // Поиск where('key', ...) внутри цикла дал бы запрос на каждый
    // ключ: девять на автомобиль и 108 на прогоне сида.
    carAttributeFixtures();
    $car = Car::factory()->create();

    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "car_attributes"')) {
            $reads++;
        }
    });

    $car->syncAttributeValues([
        'body_type' => 'Седан',
        'clearance' => 190,
        'customs_cleared' => true,
        'trim' => 'Люкс',
        'internal_note' => 'служебное',
    ]);

    // Нижняя граница — не формальность: без неё тест прошёл бы и при
    // счётчике, который вообще ничего не поймал.
    expect($reads)->toBeGreaterThan(0)
        ->and($reads)->toBeLessThanOrEqual(2);
});

it('builds the card grid without a single query when preloaded', function () {
    // Сетка рендерится в цикле карточки, и регресс здесь не виден
    // глазом — только счётчиком запросов.
    carAttributeFixtures();
    $car = Car::factory()->create();
    $car->syncAttributeValues(['body_type' => 'Седан', 'clearance' => 190, 'trim' => 'Люкс']);

    $loaded = Car::query()->with('attributeValues.attribute')->findOrFail($car->id);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $loaded->cardAttributes();
    $loaded->attributeValue('body_type');

    expect($queries)->toBe(0);
});
