<?php

use App\Models\Employee;

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

it('returns null photo url when no photo is uploaded', function () {
    $employee = Employee::factory()->create(['photo_path' => null]);

    expect($employee->photo_url)->toBeNull();
});

it('builds a public url for an uploaded photo', function () {
    $employee = Employee::factory()->create(['photo_path' => 'employees/ivanov.jpg']);

    expect($employee->photo_url)->toContain('employees/ivanov.jpg');
});
