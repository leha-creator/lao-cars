<?php

/*
 * CRUD справочника категорий услуг (веха 4.13).
 *
 * До вехи категорий было ровно пять и жили они кейсами енама: добавить
 * шестую значило выкатить релиз. Образец раздела назван заказчиком дословно
 * — «как марки авто», и `BrandResourceTest` здесь тоже образец.
 *
 * Отдельный акцент — на ДВУХ запретах, и отказы у них разного сорта:
 *
 * 1. категория с позициями: внешний ключ `services.service_category_id`
 *    объявлен `restrictOnDelete()`, и без проверки в действии администратор
 *    получил бы страницу ошибки вместо объяснения;
 * 2. последняя категория страницы запчастей: база такое удаление разрешит
 *    молча, а посадочная страница подбора после него отдаст 200 без единого
 *    блока — и заметить это снаружи некому. Тот же запрет обязан стоять
 *    на СМЕНЕ СТРАНИЦЫ: иначе администратор обходит его выпадающим списком.
 *
 * Пять исходных категорий заводит миграция вехи 4.13 на любой базе, включая
 * тестовую, и «Запчасти» среди них — поэтому тесты про последнюю категорию
 * запчастей приводят справочник к нужному состоянию явно.
 */

use App\Enums\ServicePage;
use App\Filament\Resources\ServiceCategories\Pages\CreateServiceCategory;
use App\Filament\Resources\ServiceCategories\Pages\EditServiceCategory;
use App\Filament\Resources\ServiceCategories\Pages\ListServiceCategories;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Filament\Actions\DeleteAction;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows service categories in the list', function () {
    $categories = ServiceCategory::factory()->count(3)->create();

    livewire(ListServiceCategories::class)
        ->assertOk()
        ->assertCanSeeTableRecords($categories);
});

it('creates a category', function () {
    livewire(CreateServiceCategory::class)
        ->fillForm([
            'name' => 'Кузовной ремонт',
            'slug' => 'body-repair',
            'page' => ServicePage::Services->value,
            'description' => 'Абзац под названием категории.',
            'sort_order' => 7,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('service_categories', [
        'name' => 'Кузовной ремонт',
        'slug' => 'body-repair',
        'page' => ServicePage::Services->value,
        'sort_order' => 7,
    ]);
});

it('edits a category', function () {
    $category = ServiceCategory::factory()->create(['name' => 'Старое название']);

    livewire(EditServiceCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['name' => 'Новое название'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->refresh()->name)->toBe('Новое название');
});

it('generates a transliterated slug when the field is left empty', function () {
    // Слаг — это ЯКОРЬ блока на странице услуг (`/services#detailing`),
    // и кириллица в нём дала бы процентную кашу в адресной строке.
    livewire(CreateServiceCategory::class)
        ->fillForm([
            'name' => 'Шиномонтаж и хранение',
            'slug' => null,
            'page' => ServicePage::Services->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ServiceCategory::query()->where('name', 'Шиномонтаж и хранение')->value('slug'))
        ->toBe('sinomontaz-i-xranenie');
});

it('rejects a duplicate slug with a validation error instead of a database failure', function () {
    ServiceCategory::factory()->create(['slug' => 'test-detailing']);

    livewire(CreateServiceCategory::class)
        ->fillForm([
            'name' => 'Второй детейлинг',
            'slug' => 'test-detailing',
            'page' => ServicePage::Services->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('rejects a slug that is not lowercase latin with dashes', function () {
    livewire(CreateServiceCategory::class)
        ->fillForm([
            'name' => 'Детейлинг',
            'slug' => 'Детейлинг',
            'page' => ServicePage::Services->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('requires a name', function () {
    livewire(CreateServiceCategory::class)
        ->fillForm([
            'name' => null,
            'page' => ServicePage::Services->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

it('cancels deletion of a category that still has positions, without a database exception', function () {
    $category = ServiceCategory::factory()->create();

    Service::factory()->inCategory($category)->create();

    livewire(EditServiceCategory::class, ['record' => $category->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(ServiceCategory::query()->whereKey($category->getKey())->exists())->toBeTrue();
});

it('deletes a category that has no positions', function () {
    $category = ServiceCategory::factory()->create();

    livewire(EditServiceCategory::class, ['record' => $category->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(ServiceCategory::query()->whereKey($category->getKey())->exists())->toBeFalse();
});

it('cancels deletion of the last parts category', function () {
    // Приводим справочник к состоянию «на странице запчастей ровно одна
    // категория»: пять исходных заводит миграция.
    ServiceCategory::query()->where('page', ServicePage::Parts)->delete();

    $last = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);

    livewire(EditServiceCategory::class, ['record' => $last->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(ServiceCategory::query()->whereKey($last->getKey())->exists())->toBeTrue();
});

it('allows deleting a parts category while another one is left', function () {
    // Обратная сторона того же запрета: он про ПОСЛЕДНЮЮ категорию,
    // а не про категории страницы запчастей вообще. Без этого теста
    // проверка «эта категория — запчасти» прошла бы за проверку
    // «эта категория последняя».
    ServiceCategory::query()->where('page', ServicePage::Parts)->delete();

    $first = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts-one']);
    ServiceCategory::factory()->parts()->create(['slug' => 'test-parts-two']);

    livewire(EditServiceCategory::class, ['record' => $first->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(ServiceCategory::query()->whereKey($first->getKey())->exists())->toBeFalse();
});

it('cancels moving the last parts category to another page', function () {
    // Тот же отказ другим путём: без этой проверки администратор обходит
    // запрет удаления, просто переключив выпадающий список, — категория
    // остаётся, а посадочная теряет единственный блок.
    ServiceCategory::query()->where('page', ServicePage::Parts)->delete();

    $last = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts']);

    livewire(EditServiceCategory::class, ['record' => $last->getRouteKey()])
        ->fillForm(['page' => ServicePage::Services->value])
        ->call('save');

    expect($last->refresh()->page)->toBe(ServicePage::Parts);
});

it('allows moving a parts category to another page while one is left', function () {
    ServiceCategory::query()->where('page', ServicePage::Parts)->delete();

    $moved = ServiceCategory::factory()->parts()->create(['slug' => 'test-parts-one']);
    ServiceCategory::factory()->parts()->create(['slug' => 'test-parts-two']);

    livewire(EditServiceCategory::class, ['record' => $moved->getRouteKey()])
        ->fillForm(['page' => ServicePage::Services->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($moved->refresh()->page)->toBe(ServicePage::Services);
});

it('offers no bulk delete, which would bypass both checks', function () {
    // `DeleteBulkAction` удаляет строки ОДНИМ запросом мимо `before()`,
    // то есть обошёл бы и запрет на категорию с позициями, и запрет
    // на последнюю категорию запчастей. Тот же комментарий-сторож стоит
    // у марок.
    $table = livewire(ListServiceCategories::class)->instance()->getTable();

    expect($table->getBulkActions())->toBeEmpty();
});
