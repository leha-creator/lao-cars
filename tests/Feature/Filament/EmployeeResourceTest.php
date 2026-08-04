<?php

/*
 * CRUD команды на странице «О компании» (веха 3.5).
 *
 * Здесь же проверяется первый потребитель медиабиблиотеки: MediaPicker
 * со связью и отсутствие N+1 на списке с превью.
 */

use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows employees in the list', function () {
    $employees = Employee::factory()->count(3)->create();

    livewire(ListEmployees::class)
        ->assertOk()
        ->assertCanSeeTableRecords($employees);
});

it('stores the media chosen through the picker', function () {
    $media = Media::factory()->create();

    livewire(CreateEmployee::class)
        ->fillForm([
            'name' => 'Андрей Волков',
            'position' => 'Старший мастер автосервиса',
            'bio' => null,
            'media_id' => $media->getKey(),
            'is_published' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::where('name', 'Андрей Волков')->firstOrFail();

    expect($employee->media_id)->toBe($media->getKey())
        ->and($employee->photo_url)->toContain($media->path);
});

it('edits an employee', function () {
    $employee = Employee::factory()->create(['position' => 'Старая должность']);

    livewire(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm(['position' => 'Новая должность'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->refresh()->position)->toBe('Новая должность');
});

it('does not run one query per row on the list', function () {
    Employee::factory()->count(8)->for(Media::factory())->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    livewire(ListEmployees::class)->assertOk();

    // Обе границы обязательны (правило RULES.md): без нижней пустая
    // выборка дала бы счётчик 0, и тест прошёл бы вхолостую.
    expect($queries)->toBeGreaterThan(0)
        ->and($queries)->toBeLessThan(8);
});

it('keeps the media record when the employee is deleted', function () {
    // Связь односторонняя: файл переиспользуемый, и удаление карточки
    // не должно уносить изображение из библиотеки.
    $media = Media::factory()->create();
    $employee = Employee::factory()->for($media)->create();

    $employee->delete();

    expect(Media::whereKey($media->getKey())->exists())->toBeTrue();
});
