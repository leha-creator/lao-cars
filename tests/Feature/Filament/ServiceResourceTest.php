<?php

/*
 * CRUD услуг и категорий запчастей (веха 3.5, схема правлена вехой 4.13).
 *
 * Отдельный акцент — на двух местах, где ошибка молчит: уточнение
 * к цене без самой цены (теперь ошибка формы, а не молчаливая потеря)
 * и пересортировка в общем списке, которая
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
use App\Models\Media;
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
    $media = Media::factory()->create();

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

it('shows the price note and the upper bound on creation before any price is typed', function () {
    // Жалоба заказчика: «уточнение появляется только при редактировании».
    // Поле скрывалось при пустой цене, а при создании цена пуста всегда —
    // до перерисовки по уходу фокуса поля не было видно вовсе.
    livewire(CreateService::class)
        ->assertFormFieldIsVisible('price_note')
        ->assertFormFieldIsVisible('price_max');
});

it('requires a price when a price note is filled instead of dropping the note', function () {
    // «от» без суммы — мусор в вёрстке прайса. Раньше скрытое поле его
    // молча выбрасывало, теперь форма объясняет, чего не хватает.
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
        ->assertHasFormErrors(['price' => 'required_with']);

    expect(Service::where('title', 'Позиция без цены')->exists())->toBeFalse();
});

it('clears the price note on edit when it is emptied together with the price', function () {
    // Сценарий, в котором скрытое поле врало: на РЕДАКТИРОВАНИИ Filament
    // скрытое поле не сохраняет, и очищенная цена оставляла «от» в базе.
    $service = Service::factory()->create(['price' => 2500, 'price_note' => 'от']);

    livewire(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['price' => null, 'price_note' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($service->refresh()->price)->toBeNull()
        ->and($service->price_note)->toBeNull();
});

it('saves a price range', function () {
    $service = Service::factory()->create(['price' => 9000]);

    livewire(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['price_max' => 15000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($service->refresh()->price_max)->toBe(15000)
        ->and($service->priceLabel())->toBe("от\u{a0}9\u{a0}000 до\u{a0}15\u{a0}000\u{a0}₽");
});

it('rejects an upper bound that is not greater than the price', function () {
    $service = Service::factory()->create(['price' => 9000]);

    livewire(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['price_max' => 9000])
        ->call('save')
        ->assertHasFormErrors(['price_max' => 'gt']);

    expect($service->refresh()->price_max)->toBeNull();
});

it('shows the same price line in the table as on the site', function () {
    // Третий потребитель формата после страницы и карточки: `->money()`
    // показывал голую нижнюю границу, а колонка «Уточнение» жила отдельно.
    $range = Service::factory()->withPriceRange(6000)->create(['price' => 4500, 'price_note' => 'за сезон']);
    $onRequest = Service::factory()->withoutPrice()->create();

    livewire(ListServices::class)
        ->assertTableColumnFormattedStateSet('price', "от\u{a0}4\u{a0}500 до\u{a0}6\u{a0}000\u{a0}₽ за сезон", $range)
        ->assertTableColumnFormattedStateNotSet('price', "от\u{a0}4\u{a0}500 до\u{a0}6\u{a0}000\u{a0}₽ за сезон", $onRequest);
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
