<?php

use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use Illuminate\Database\QueryException;

it('belongs to a car and to an attribute', function () {
    $car = Car::factory()->create();
    $attribute = CarAttribute::factory()->text()->create();

    $value = CarAttributeValue::factory()->for($car)->forAttribute($attribute, 'Люкс')->create();

    expect($value->car->is($car))->toBeTrue()
        // Внешний ключ у связи задан явно: Eloquent вывел бы его из
        // имени метода как attribute_id и молча отдавал бы null.
        ->and($value->attribute->is($attribute))->toBeTrue()
        ->and($car->attributeValues)->toHaveCount(1)
        ->and($attribute->values)->toHaveCount(1);
});

it('casts and formats the value through its attribute', function () {
    $clearance = CarAttribute::factory()->number('мм')->create();

    $value = CarAttributeValue::factory()->forAttribute($clearance, '190')->create();

    expect($value->casted)->toBe(190)
        ->and($value->formatted)->toBe('190 мм');
});

it('deletes values together with the car', function () {
    $car = Car::factory()->create();
    CarAttributeValue::factory()->count(2)->for($car)->create();

    $car->delete();

    expect(CarAttributeValue::query()->count())->toBe(0);
});

it('deletes values together with the attribute', function () {
    // Осиротевшее значение нечем отобразить — нет ни подписи,
    // ни типа, ни единицы измерения.
    $attribute = CarAttribute::factory()->text()->create();
    CarAttributeValue::factory()->count(2)->create(['car_attribute_id' => $attribute->id]);

    $attribute->delete();

    expect(CarAttributeValue::query()->count())->toBe(0);
});

it('rejects a second value for the same car and attribute', function () {
    $car = Car::factory()->create();
    $attribute = CarAttribute::factory()->text()->create();

    CarAttributeValue::factory()->for($car)->forAttribute($attribute, 'Люкс')->create();
    CarAttributeValue::factory()->for($car)->forAttribute($attribute, 'База')->create();
})->throws(QueryException::class);
