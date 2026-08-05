<?php

use App\Models\Brand;
use App\Models\Car;
use App\Services\SimilarCars;

/*
 * Подбор похожих автомобилей для карточки (веха 3.6).
 *
 * Два круга: сначала та же марка по близости цены, затем добор из других
 * марок в коридоре ±30%. Автомобиль без цены — не исключение из правила,
 * а его отдельная ветка: коридора вокруг NULL не существует.
 */

/**
 * @return array<int, string>
 */
function similarModels(Car $car, ?int $limit = null): array
{
    return app(SimilarCars::class)->for($car, $limit)->pluck('model')->all();
}

it('prefers cars of the same brand', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $other = Brand::factory()->create(['name' => 'Voyah']);

    $car = Car::factory()->for($zeekr)->create(['model' => '001', 'price' => 5_000_000]);
    Car::factory()->for($zeekr)->create(['model' => '007', 'price' => 5_100_000]);
    Car::factory()->for($zeekr)->create(['model' => 'X', 'price' => 4_900_000]);
    Car::factory()->for($other)->create(['model' => 'Free', 'price' => 5_000_000]);

    // Одномарочных хватило — второго круга не будет вовсе.
    expect(similarModels($car, 2))->toBe(['X', '007']);
});

it('never includes the car itself', function () {
    $brand = Brand::factory()->create();
    $car = Car::factory()->for($brand)->create(['model' => '001', 'price' => 5_000_000]);
    Car::factory()->for($brand)->create(['model' => '007', 'price' => 5_000_000]);

    expect(similarModels($car))->not->toContain('001');
});

it('never includes a sold car', function () {
    $brand = Brand::factory()->create();
    $car = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    Car::factory()->for($brand)->sold()->create(['model' => 'Monjaro', 'price' => 5_000_000]);

    expect(similarModels($car))->toBe([]);
});

it('fills the block from other brands when the same brand is short', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $voyah = Brand::factory()->create(['name' => 'Voyah']);

    $car = Car::factory()->for($zeekr)->create(['model' => '001', 'price' => 5_000_000]);
    Car::factory()->for($zeekr)->create(['model' => '007', 'price' => 5_100_000]);
    Car::factory()->for($voyah)->create(['model' => 'Free', 'price' => 4_500_000]);
    // За пределами коридора ±30% — в добор не попадает.
    Car::factory()->for($voyah)->create(['model' => 'Dream', 'price' => 20_000_000]);

    $similar = similarModels($car, 3);

    expect($similar)->toBe(['007', 'Free'])
        ->and($similar)->not->toContain('Dream');
});

it('orders same-brand cars by price proximity', function () {
    $brand = Brand::factory()->create();
    $car = Car::factory()->for($brand)->create(['price' => 5_000_000]);

    Car::factory()->for($brand)->create(['model' => 'Далёкая', 'price' => 3_000_000]);
    Car::factory()->for($brand)->create(['model' => 'Ближняя', 'price' => 5_050_000]);

    expect(similarModels($car, 2))->toBe(['Ближняя', 'Далёкая']);
});

it('does not break on a car without a price', function () {
    $brand = Brand::factory()->create();
    $car = Car::factory()->for($brand)->withoutPrice()->create();

    Car::factory()->for($brand)->create(['model' => 'Свежая', 'created_at' => now()]);
    Car::factory()->for($brand)->create(['model' => 'Старая', 'created_at' => now()->subYear()]);

    // Коридора вокруг NULL не существует: попытка его посчитать дала бы
    // NULL в каждом сравнении и пустой блок.
    expect(similarModels($car, 2))->toBe(['Свежая', 'Старая']);
});

it('fills the block for a car without a price from other brands too', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $voyah = Brand::factory()->create(['name' => 'Voyah']);

    $car = Car::factory()->for($zeekr)->withoutPrice()->create();
    Car::factory()->for($voyah)->create(['model' => 'Free', 'price' => 4_500_000]);

    expect(similarModels($car, 3))->toBe(['Free']);
});

it('never returns more than the configured limit', function () {
    $brand = Brand::factory()->create();
    $car = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    Car::factory()->count(10)->for($brand)->create(['price' => 5_000_000]);

    expect(app(SimilarCars::class)->for($car))
        ->toHaveCount((int) config('catalog.similar_limit'));
});
