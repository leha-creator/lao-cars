<?php

use App\Models\Employee;
use App\Models\Media;

it('hides unpublished employees from public queries', function () {
    Employee::factory()->count(3)->create();
    Employee::factory()->unpublished()->create();

    expect(Employee::published()->count())->toBe(3)
        ->and(Employee::count())->toBe(4);
});

it('orders employees by sort order and then by name', function () {
    Employee::factory()->create(['name' => 'Борис', 'sort_order' => 1]);
    Employee::factory()->create(['name' => 'Анна', 'sort_order' => 1]);
    Employee::factory()->create(['name' => 'Виктор', 'sort_order' => 0]);

    expect(Employee::ordered()->pluck('name')->all())->toBe(['Виктор', 'Анна', 'Борис']);
});

it('returns null photo url when no media is attached', function () {
    $employee = Employee::factory()->create(['media_id' => null]);

    expect($employee->photo_url)->toBeNull();
});

it('builds a public url through the media relation', function () {
    $media = Media::factory()->create(['path' => 'media/ivanov.webp']);
    $employee = Employee::factory()->for($media)->create();

    expect($employee->photo_url)->toContain('media/ivanov.webp');
});

it('keeps the employee when its media record is deleted', function () {
    $media = Media::factory()->create();
    $employee = Employee::factory()->for($media)->create();

    $media->delete();

    expect($employee->refresh()->media_id)->toBeNull()
        ->and($employee->photo_url)->toBeNull();
});
