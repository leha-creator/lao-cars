<?php

/*
 * CRUD услуг и категорий запчастей (веха 3.5, схема правлена вехой 4.13).
 *
 * Отдельный акцент — на двух местах, где ошибка молчит: уточнение
 * к цене без самой цены и пересортировка в общем списке, которая
 * присвоила бы сквозные номера и перемешала все блоки страницы
 * автосервиса разом.
 *
 * Категория с вехи 4.13 — строка справочника, а не кейс енама, поэтому
 * данные заводятся фабрикой `ServiceCategory`, а вкладки списка ключуются
 * СЛАГОМ категории. Проверяемые свойства прежние: вкладка сужает выборку,
 * пересортировка живёт только внутри вкладки.
 */

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Service;
use App\Models\ServiceCategory;
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
    $category = ServiceCategory::factory()->create();

    livewire(CreateService::class)
        ->fillForm([
            'service_category_id' => $category->getKey(),
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
        'service_category_id' => $category->getKey(),
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

it('saves the photo, the featured flag and the details', function () {
    // Три поля вехи 4.13 в одной секции формы. Без сторожа их потеря
    // выглядит как «карточка не изменилась»: страница отдаёт 200,
    // позиция на месте, просто рисуется прежним видом.
    $category = ServiceCategory::factory()->create();
    $media = App\Models\Media::factory()->create();

    livewire(CreateService::class)
        ->fillForm([
            'service_category_id' => $category->getKey(),
            'title' => 'Акцентная позиция',
            'slug' => null,
            'media_id' => $media->getKey(),
            'is_featured' => true,
            'details' => 'Подробное описание услуги.',
            'price' => 35000,
            'price_note' => 'от',
            'is_published' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('services', [
        'title' => 'Акцентная позиция',
        'media_id' => $media->getKey(),
        'is_featured' => true,
        'details' => 'Подробное описание услуги.',
    ]);
});

it('does not save a price note without a price', function () {
    // Скрытый компонент не дегидрируется, поэтому очистка цены заодно
    // снимает и уточнение: «от» без суммы — мусор в вёрстке прайса.
    $category = ServiceCategory::factory()->create();

    livewire(CreateService::class)
        ->fillForm([
            'service_category_id' => $category->getKey(),
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
    $tireCategory = ServiceCategory::factory()->create(['slug' => 'test-tire-service']);
    $partsCategory = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);

    $tire = Service::factory()->count(2)->inCategory($tireCategory)->create();
    $parts = Service::factory()->inCategory($partsCategory)->create();

    livewire(ListServices::class)
        // Ключ вкладки — слаг категории: значений енама больше нет,
        // а идентификатор в адресной строке ничего не говорит человеку.
        ->set('activeTab', $tireCategory->slug)
        ->assertCanSeeTableRecords($tire)
        ->assertCanNotSeeTableRecords([$parts]);
});

it('allows reordering inside a category tab but not on the all tab', function () {
    // Утверждение теста не правилось вехой 4.13 и не должно: порядок
    // выдачи сайта («сначала акцентные, затем с фотографией») в таблицу
    // НЕ переносится — `defaultSort` и `reorderable` пара, и подмена
    // сортировки сломала бы перетаскивание.
    $category = ServiceCategory::factory()->create(['slug' => 'test-tire-service']);

    Service::factory()->inCategory($category)->create();

    $component = livewire(ListServices::class)
        ->set('activeTab', ListServices::ALL_TAB);

    expect($component->instance()->getTable()->isReorderable())->toBeFalse();

    $component->set('activeTab', $category->slug);

    expect($component->instance()->getTable()->isReorderable())->toBeTrue();
});
