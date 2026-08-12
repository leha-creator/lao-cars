<?php

use App\Enums\CarAttributeType;
use App\Enums\CarStatus;
use App\Enums\ServiceCategory;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use App\Models\CarPhoto;
use App\Models\Employee;
use App\Models\Review;
use App\Models\Service;
use App\Models\Setting;

it('fills every content table', function () {
    $this->seed();

    expect(Brand::count())->toBeGreaterThan(0)
        ->and(Car::count())->toBeGreaterThan(0)
        ->and(CarAttribute::count())->toBeGreaterThan(0)
        ->and(CarAttributeValue::count())->toBeGreaterThan(0)
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
        'attributes' => CarAttribute::count(),
        'attribute_values' => CarAttributeValue::count(),
    ];

    $this->seed();

    expect(Brand::count())->toBe($counts['brands'])
        ->and(Car::count())->toBe($counts['cars'])
        ->and(CarAttribute::count())->toBe($counts['attributes'])
        // Значения задаются константой, без fake(): случайные
        // переписывались бы updateOrCreate на каждом прогоне.
        ->and(CarAttributeValue::count())->toBe($counts['attribute_values'])
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
        // Блоки главной вехи 4.6: без умолчаний в сиде свежезасеянная база
        // даёт главную без этапов, состава цены и FAQ — то есть без трёх
        // из четырнадцати блоков, и выглядит это не как пустая настройка,
        // а как недоделанная страница.
        ->and(Setting::get('home.steps'))->toBeArray()
        ->and(Setting::get('home.price_breakdown'))->toBeArray()
        ->and(Setting::get('home.faq'))->toBeArray()
        ->and(Setting::get('footer.guarantee'))->toHaveKey('title')
        ->and(Setting::get('seo.default_title'))->not->toBeNull();
});

it('seeds a catalog that exercises every card state', function () {
    $this->seed();

    expect(Car::onHomepage()->count())->toBeGreaterThan(0)
        ->and(Car::onOrder()->count())->toBeGreaterThan(0)
        ->and(Car::query()->whereNull('price')->count())->toBeGreaterThan(0)
        ->and(Car::query()->whereNull('mileage')->count())->toBeGreaterThan(0)
        // Без авто в пути в демо-данных кнопка «В пути» на главной
        // не появилась бы вовсе — она строится из фактически встреченных
        // статусов, и новый статус выглядел бы как несработавшая правка.
        ->and(Car::query()->where('status', CarStatus::InTransit)->count())->toBeGreaterThan(0)
        ->and(Car::onHomepage()->where('status', CarStatus::InTransit)->count())->toBeGreaterThan(0);
});

it('keeps the homepage selection non-empty after the status change', function () {
    // `HomeContent::cars()` пересекает `onHomepage()` с `available()`.
    // Перевод отмеченного автомобиля в «В пути» её не ломает, а перевод
    // в «Продан» сломал бы — и при правке литерала статусов в сиде это
    // делается соседней строкой.
    $this->seed();

    expect(Car::onHomepage()->available()->count())->toBeGreaterThan(0);
});

it('seeds an attribute of every type so no branch stays uncovered', function () {
    // Веха 3.4 строит редактор значений, 4.3 — сетку карточки; обе
    // должны столкнуться с каждым типом на демо-данных.
    $this->seed();

    foreach (CarAttributeType::cases() as $type) {
        expect(CarAttribute::query()->where('type', $type)->count())
            ->toBeGreaterThan(0, "тип {$type->value} не засеян");
    }
});

it('keeps every seeded select value inside its option list', function () {
    // Значение вне options — это вариант фильтра вехи 3.6,
    // по которому ничего не находится.
    $this->seed();

    $invalid = CarAttributeValue::query()->with('attribute')->get()
        ->reject(fn (CarAttributeValue $value): bool => $value->attribute->isValidValue($value->value));

    expect($invalid)->toBeEmpty();
});

it('seeds cars both with and without attributes', function () {
    // Сетка карточки должна проверяться и на пустом наборе,
    // и такой случай обязан быть в демо-данных, а не на проде.
    $this->seed();

    expect(Car::query()->has('attributeValues')->count())->toBeGreaterThan(0)
        ->and(Car::query()->doesntHave('attributeValues')->count())->toBeGreaterThan(0);
});
