<?php

use App\Enums\ServiceCategory;
use App\Models\Lead;
use App\Models\Service;

it('casts category to an enum', function () {
    $service = Service::factory()->parts()->create();

    $service->refresh();

    expect($service->category)->toBe(ServiceCategory::Parts);
});

it('filters services by category', function () {
    Service::factory()->count(3)->parts()->create();
    Service::factory()->count(2)->detailing()->create();

    expect(Service::inCategory(ServiceCategory::Parts)->count())->toBe(3)
        ->and(Service::inCategory(ServiceCategory::Detailing)->count())->toBe(2)
        ->and(Service::inCategory(ServiceCategory::Maintenance)->count())->toBe(0);
});

it('hides unpublished services from public queries', function () {
    Service::factory()->count(2)->maintenance()->create();
    Service::factory()->maintenance()->unpublished()->create();

    expect(Service::published()->count())->toBe(2)
        ->and(Service::count())->toBe(3);
});

it('orders services by sort order and then by title', function () {
    Service::factory()->create(['title' => 'Второй', 'sort_order' => 1]);
    Service::factory()->create(['title' => 'Первый', 'sort_order' => 0]);

    expect(Service::ordered()->pluck('title')->all())->toBe(['Первый', 'Второй']);
});

it('builds a transliterated slug from a russian title', function () {
    $service = Service::factory()->create(['title' => 'Шиномонтаж легкового автомобиля']);

    // Кириллица должна давать читаемый латинский slug, а не пустую строку.
    expect($service->slug)->toBe('sinomontaz-legkovogo-avtomobilia');
});

it('allows a null price meaning "on request"', function () {
    $service = Service::factory()->parts()->withoutPrice()->create();

    expect($service->price)->toBeNull()
        ->and($service->price_note)->toBeNull();
});

it('excludes parts from the service page categories', function () {
    $categories = ServiceCategory::serviceCategories();

    expect($categories)->toHaveCount(4)
        ->and($categories)->not->toContain(ServiceCategory::Parts);
});

it('collects leads submitted for a service', function () {
    $service = Service::factory()->maintenance()->create();

    Lead::factory()->forService($service)->create();

    expect($service->leads)->toHaveCount(1);
});
