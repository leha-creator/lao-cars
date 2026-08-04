<?php

/*
 * CRUD услуг и категорий запчастей (веха 3.5).
 *
 * Отдельный акцент — на двух местах, где ошибка молчит: уточнение
 * к цене без самой цены и пересортировка в общем списке, которая
 * присвоила бы сквозные номера и перемешала все блоки страницы
 * автосервиса разом.
 */

use App\Enums\ServiceCategory;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Service;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows services in the list', function () {
    $services = Service::factory()->count(3)->create();

    livewire(ListServices::class)
        ->assertOk()
        ->assertCanSeeTableRecords($services);
});

it('creates a service', function () {
    livewire(CreateService::class)
        ->fillForm([
            'category' => ServiceCategory::Maintenance->value,
            'title' => 'Замена масла',
            'slug' => null,
            'price' => 2500,
            'price_note' => 'от',
            'is_published' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('services', [
        'title' => 'Замена масла',
        // Slug генерируется трейтом HasSlug с транслитерацией кириллицы.
        'slug' => 'zamena-masla',
        'price' => 2500,
        'price_note' => 'от',
    ]);
});

it('edits a service', function () {
    $service = Service::factory()->create(['title' => 'Старое название']);

    livewire(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['title' => 'Новое название'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($service->refresh()->title)->toBe('Новое название');
});

it('does not save a price note without a price', function () {
    // Скрытый компонент не дегидрируется, поэтому очистка цены заодно
    // снимает и уточнение: «от» без суммы — мусор в вёрстке прайса.
    livewire(CreateService::class)
        ->fillForm([
            'category' => ServiceCategory::Extra->value,
            'title' => 'Позиция без цены',
            'slug' => null,
            'price' => null,
            'price_note' => 'от',
            'is_published' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Service::where('title', 'Позиция без цены')->value('price_note'))->toBeNull();
});

it('shows only the records of the active category tab', function () {
    $tire = Service::factory()->count(2)->create(['category' => ServiceCategory::TireService]);
    $parts = Service::factory()->create(['category' => ServiceCategory::Parts]);

    livewire(ListServices::class)
        ->set('activeTab', ServiceCategory::TireService->value)
        ->assertCanSeeTableRecords($tire)
        ->assertCanNotSeeTableRecords([$parts]);
});

it('allows reordering inside a category tab but not on the all tab', function () {
    Service::factory()->create(['category' => ServiceCategory::TireService]);

    $component = livewire(ListServices::class)
        ->set('activeTab', ListServices::ALL_TAB);

    expect($component->instance()->getTable()->isReorderable())->toBeFalse();

    $component->set('activeTab', ServiceCategory::TireService->value);

    expect($component->instance()->getTable()->isReorderable())->toBeTrue();
});
