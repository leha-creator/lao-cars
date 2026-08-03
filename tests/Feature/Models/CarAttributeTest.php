<?php

use App\Enums\CarAttributeType;
use App\Models\CarAttribute;
use Illuminate\Database\QueryException;

it('casts a stored string to the type of the attribute', function () {
    $text = CarAttribute::factory()->text()->create();
    $number = CarAttribute::factory()->number()->create();
    $boolean = CarAttribute::factory()->boolean()->create();
    $select = CarAttribute::factory()->select(['Седан'])->create();

    expect($text->cast('Люкс'))->toBe('Люкс')
        // Целое там, где точки нет: иначе в карточке «4.0 двери».
        ->and($number->cast('4'))->toBe(4)
        ->and($number->cast('2.5'))->toBe(2.5)
        ->and($boolean->cast('1'))->toBeTrue()
        ->and($boolean->cast('0'))->toBeFalse()
        ->and($select->cast('Седан'))->toBe('Седан');
});

it('returns null when there is nothing to cast', function () {
    expect(CarAttribute::factory()->number()->create()->cast(null))->toBeNull();
});

it('appends the unit when formatting a number', function () {
    $clearance = CarAttribute::factory()->number('мм')->create(['label' => 'Клиренс']);
    $doors = CarAttribute::factory()->number()->create(['label' => 'Количество дверей']);

    expect($clearance->format('190'))->toBe('190 мм')
        ->and($doors->format('4'))->toBe('4');
});

it('formats a boolean as "Да" or "Нет"', function () {
    $customs = CarAttribute::factory()->boolean()->create();

    expect($customs->format('1'))->toBe('Да')
        ->and($customs->format('0'))->toBe('Нет');
});

it('formats nothing as null', function () {
    expect(CarAttribute::factory()->boolean()->create()->format(null))->toBeNull();
});

it('accepts only values from the option list of a select', function () {
    $body = CarAttribute::factory()->select(['Седан', 'Кроссовер'])->create();

    expect($body->isValidValue('Седан'))->toBeTrue()
        // Латинская «e» в «Кроссовeр»: ровно так фильтр вехи 3.6
        // получает вариант, по которому ничего не находится.
        ->and($body->isValidValue('Кроссовeр'))->toBeFalse();
});

it('accepts any value for types without an option list', function () {
    expect(CarAttribute::factory()->text()->create()->isValidValue('что угодно'))->toBeTrue()
        ->and(CarAttribute::factory()->number()->create()->isValidValue('190'))->toBeTrue()
        ->and(CarAttribute::factory()->boolean()->create()->isValidValue('0'))->toBeTrue();
});

it('accepts nothing for a select without an option list', function () {
    // Справочник без заполненного списка — ошибка настройки,
    // а не разрешение писать что угодно.
    $broken = CarAttribute::factory()->create(['type' => CarAttributeType::Select, 'options' => null]);

    expect($broken->isValidValue('Седан'))->toBeFalse();
});

it('selects attributes shown in the card and in the filter', function () {
    CarAttribute::factory()->count(2)->create();
    CarAttribute::factory()->hiddenInCard()->create();
    CarAttribute::factory()->inFilter()->create();

    expect(CarAttribute::inCard()->count())->toBe(3)
        ->and(CarAttribute::inFilter()->count())->toBe(1);
});

it('orders attributes by sort order and then by label', function () {
    CarAttribute::factory()->create(['label' => 'Цвет', 'sort_order' => 20]);
    CarAttribute::factory()->create(['label' => 'Кузов', 'sort_order' => 10]);
    // Одинаковый порядок — разрешается подписью, а не тем,
    // как строки легли в таблицу.
    CarAttribute::factory()->create(['label' => 'Клиренс', 'sort_order' => 20]);

    expect(CarAttribute::ordered()->pluck('label')->all())
        ->toBe(['Кузов', 'Клиренс', 'Цвет']);
});

it('rejects a duplicate key', function () {
    CarAttribute::factory()->create(['key' => 'body_type']);

    CarAttribute::factory()->create(['key' => 'body_type']);
})->throws(QueryException::class);

it('casts the option list to an array', function () {
    $body = CarAttribute::factory()->select(['Седан', 'Купе'])->create();

    expect($body->fresh()->options)->toBe(['Седан', 'Купе'])
        ->and($body->fresh()->type)->toBe(CarAttributeType::Select);
});
