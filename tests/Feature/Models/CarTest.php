<?php

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Lead;

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
