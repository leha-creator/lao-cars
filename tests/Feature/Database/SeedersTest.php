<?php

use App\Enums\ServiceCategory;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarPhoto;
use App\Models\Employee;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;

it('fills every content table', function () {
    $this->seed();

    expect(Brand::count())->toBeGreaterThan(0)
        ->and(Car::count())->toBeGreaterThan(0)
        ->and(Service::count())->toBeGreaterThan(0)
        ->and(Employee::count())->toBeGreaterThan(0)
        ->and(Review::count())->toBeGreaterThan(0)
        ->and(Setting::count())->toBeGreaterThan(0);
});

it('does not copy photo files while testing', function () {
    // CarPhotoSeeder пропускается в тестовом окружении: в assets/cars
    // 46 файлов на 128 МБ, и копирование на каждом прогоне
    // RefreshDatabase сделало бы тесты неприемлемо медленными.
    $this->seed();

    expect(CarPhoto::count())->toBe(0);
});

it('stays idempotent on a repeated run', function () {
    $this->seed();

    $counts = [
        'brands' => Brand::count(),
        'cars' => Car::count(),
        'services' => Service::count(),
        'employees' => Employee::count(),
        'reviews' => Review::count(),
        'settings' => Setting::count(),
    ];

    $this->seed();

    expect(Brand::count())->toBe($counts['brands'])
        ->and(Car::count())->toBe($counts['cars'])
        ->and(Service::count())->toBe($counts['services'])
        ->and(Employee::count())->toBe($counts['employees'])
        ->and(Review::count())->toBe($counts['reviews'])
        ->and(Setting::count())->toBe($counts['settings']);
});

it('seeds every service category so the service page has no empty blocks', function () {
    $this->seed();

    foreach (ServiceCategory::cases() as $category) {
        expect(Service::inCategory($category)->published()->count())
            ->toBeGreaterThan(0, "категория {$category->value} пуста");
    }
});

it('leaves one review pending so moderation has something to show', function () {
    $this->seed();

    expect(Review::published()->count())->toBeGreaterThan(0)
        ->and(Review::pending()->count())->toBeGreaterThan(0);
});

it('seeds the settings keys the layout depends on', function () {
    $this->seed();

    expect(Setting::get('contacts.phone'))->not->toBeNull()
        ->and(Setting::get('home.ticker'))->toBeArray()
        ->and(Setting::get('home.advantages'))->toBeArray()
        ->and(Setting::get('footer.guarantee'))->toHaveKey('title')
        ->and(Setting::get('seo.default_title'))->not->toBeNull();
});

it('seeds a catalog that exercises every card state', function () {
    $this->seed();

    expect(Car::onHomepage()->count())->toBeGreaterThan(0)
        ->and(Car::onOrder()->count())->toBeGreaterThan(0)
        ->and(Car::query()->whereNull('price')->count())->toBeGreaterThan(0)
        ->and(Car::query()->whereNull('mileage')->count())->toBeGreaterThan(0);
});
