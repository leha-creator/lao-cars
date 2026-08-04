<?php

/*
 * Админка каталога автомобилей (веха 3.4).
 *
 * Помимо обычного CRUD здесь проверяются две вещи, которые ломаются
 * молча: редактор динамических характеристик (значение «Нет» обязано
 * сохраняться, а не удаляться) и отсутствие N+1 на списке.
 */

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Filament\Resources\Cars\CarResource;
use App\Filament\Resources\Cars\Pages\CreateCar;
use App\Filament\Resources\Cars\Pages\EditCar;
use App\Filament\Resources\Cars\Pages\ListCars;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * @return array<string, mixed>
 */
function carFormData(Brand $brand, array $overrides = []): array
{
    return array_merge([
        'brand_id' => $brand->id,
        'model' => 'X5',
        'slug' => null,
        'year' => 2024,
        'engine_type' => EngineType::Petrol->value,
        'drive' => DriveType::Full->value,
        'status' => CarStatus::InStock->value,
        'sort_order' => 0,
    ], $overrides);
}

it('shows cars in the list', function () {
    $cars = Car::factory()->count(3)->create();

    livewire(ListCars::class)
        ->assertOk()
        ->assertCanSeeTableRecords($cars);
});

it('creates a car and leaves empty price and mileage null rather than zero', function () {
    $brand = Brand::factory()->create();

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['price' => null, 'mileage' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    $car = Car::query()->where('model', 'X5')->sole();

    expect($car->price)->toBeNull()
        ->and($car->mileage)->toBeNull()
        // `null` в пробеге означает «новый автомобиль» — решение вехи 3.2.
        ->and($car->slug)->not->toBeEmpty();
});

it('rejects a duplicate slug with a validation error instead of a database failure', function () {
    $brand = Brand::factory()->create();
    Car::factory()->create(['slug' => 'bmw-x5-2024']);

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['slug' => 'bmw-x5-2024']))
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('requires brand, model and year', function () {
    livewire(CreateCar::class)
        ->fillForm(['brand_id' => null, 'model' => null, 'year' => null])
        ->call('create')
        ->assertHasFormErrors(['brand_id', 'model', 'year']);
});

it('edits a car', function () {
    $car = Car::factory()->create(['model' => 'X5']);

    livewire(EditCar::class, ['record' => $car->getRouteKey()])
        ->fillForm(['model' => 'X7'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($car->refresh()->model)->toBe('X7');
});

it('saves dynamic attribute values through the form', function () {
    $brand = Brand::factory()->create();
    $body = CarAttribute::factory()->text()->create(['key' => 'body_type', 'label' => 'Кузов']);
    $clearance = CarAttribute::factory()->number('мм')->create(['key' => 'clearance', 'label' => 'Клиренс']);

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, [
            'car_attributes.body_type' => 'Седан',
            'car_attributes.clearance' => '190',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $car = Car::query()->where('model', 'X5')->sole();

    $this->assertDatabaseHas('car_attribute_values', [
        'car_id' => $car->id,
        'car_attribute_id' => $body->id,
        'value' => 'Седан',
    ]);

    $this->assertDatabaseHas('car_attribute_values', [
        'car_id' => $car->id,
        'car_attribute_id' => $clearance->id,
        'value' => '190',
    ]);
});

it('normalizes a decimal comma into a dot', function () {
    $brand = Brand::factory()->create();
    $volume = CarAttribute::factory()->number('л')->create(['key' => 'trunk', 'label' => 'Багажник']);

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['car_attributes.trunk' => '2,5']))
        ->call('create')
        ->assertHasNoFormErrors();

    // `(float) '2,5'` в PHP молча даёт 2.0 — нормализация обязана
    // произойти на входе формы, а не при чтении.
    expect(Car::query()->where('model', 'X5')->sole()->attributeValues()->where('car_attribute_id', $volume->id)->value('value'))
        ->toBe('2.5');
});

it('still rejects a non-numeric value in a number attribute', function () {
    $brand = Brand::factory()->create();
    CarAttribute::factory()->number('л')->create(['key' => 'trunk', 'label' => 'Багажник']);

    // Правило `numeric` здесь заменено собственной регуляркой ради
    // запятой — проверка, что замена не сняла валидацию вовсе.
    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['car_attributes.trunk' => 'много']))
        ->call('create')
        ->assertHasFormErrors(['car_attributes.trunk']);
});

it('stores a false boolean attribute as "0" instead of deleting it', function () {
    $brand = Brand::factory()->create();
    $cleared = CarAttribute::factory()->boolean()->create(['key' => 'customs', 'label' => 'Растаможен']);

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['car_attributes.customs' => false]))
        ->call('create')
        ->assertHasNoFormErrors();

    // `empty('0')` в PHP истинно — проверка на пустоту обязана быть
    // строгой, иначе «Нет» удаляется вместо сохранения (правило RULES.md).
    $this->assertDatabaseHas('car_attribute_values', [
        'car_id' => Car::query()->where('model', 'X5')->sole()->id,
        'car_attribute_id' => $cleared->id,
        'value' => '0',
    ]);
});

it('does not store a select value outside the allowed options', function () {
    $brand = Brand::factory()->create();
    $body = CarAttribute::factory()->select(['Седан', 'Универсал'])->create(['key' => 'body_type']);

    livewire(CreateCar::class)
        ->fillForm(carFormData($brand, ['car_attributes.body_type' => 'Кабриолет']))
        ->call('create');

    $this->assertDatabaseMissing('car_attribute_values', [
        'car_attribute_id' => $body->id,
        'value' => 'Кабриолет',
    ]);
});

it('removes an attribute value when the field is cleared', function () {
    $attribute = CarAttribute::factory()->text()->create(['key' => 'colour']);
    $car = Car::factory()->create();
    $car->syncAttributeValues(['colour' => 'Синий']);

    expect($car->attributeValues()->count())->toBe(1);

    livewire(EditCar::class, ['record' => $car->getRouteKey()])
        ->fillForm(['car_attributes.colour' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($car->attributeValues()->where('car_attribute_id', $attribute->id)->exists())->toBeFalse();
});

it('hydrates a false boolean attribute as a real boolean', function () {
    CarAttribute::factory()->boolean()->create(['key' => 'customs', 'label' => 'Растаможен']);
    $car = Car::factory()->create();
    $car->syncAttributeValues(['customs' => false]);

    // Строка '0' в PHP истинна: попав в Toggle без приведения,
    // она показала бы «Да» там, где стоит «Нет».
    livewire(EditCar::class, ['record' => $car->getRouteKey()])
        ->assertFormSet(['car_attributes.customs' => false]);
});

it('builds attribute fields from the dictionary without code changes', function () {
    CarAttribute::factory()->text()->create(['key' => 'colour', 'label' => 'Цвет']);
    $car = Car::factory()->create();

    livewire(EditCar::class, ['record' => $car->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('car_attributes.colour');
});

it('does not run an N+1 query on the car list', function () {
    Car::factory()->count(12)->create()->each(
        fn (Car $car) => CarPhoto::factory()->for($car)->create(),
    );

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $rows = CarResource::getEloquentQuery()->get();

    foreach ($rows as $car) {
        $car->brand?->name;
        $car->mainPhoto?->thumb_url;
    }

    // Нижняя граница обязательна: без неё выборка, которая ничего
    // не поймала, даёт 0 и тест проходит вхолостую (правило RULES.md).
    expect($rows)->toHaveCount(12)
        ->and($queries)->toBeGreaterThan(0)
        ->and($queries)->toBeLessThan(6);
});
