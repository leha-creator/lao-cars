<?php

/*
 * CRUD марок в админке (веха 3.4).
 *
 * Отдельный акцент — на удалении марки с автомобилями: внешний ключ
 * `cars.brand_id` объявлен `restrictOnDelete()`, и без проверки в
 * действии администратор получил бы страницу ошибки вместо объяснения.
 */

use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\Car;
use App\Models\User;
use Filament\Actions\DeleteAction;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows brands in the list', function () {
    $brands = Brand::factory()->count(3)->create();

    livewire(ListBrands::class)
        ->assertOk()
        ->assertCanSeeTableRecords($brands);
});

it('generates a slug when the field is left empty', function () {
    livewire(CreateBrand::class)
        ->fillForm([
            'name' => 'Great Wall',
            'slug' => null,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Brand::query()->where('name', 'Great Wall')->value('slug'))
        ->not->toBeEmpty();
});

it('rejects a duplicate slug with a validation error instead of a database failure', function () {
    Brand::factory()->create(['slug' => 'chery']);

    livewire(CreateBrand::class)
        ->fillForm([
            'name' => 'Chery',
            'slug' => 'chery',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('rejects a slug that is not lowercase latin with dashes', function () {
    livewire(CreateBrand::class)
        ->fillForm([
            'name' => 'Хавал',
            'slug' => 'Хавал',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
});

it('requires a name', function () {
    livewire(CreateBrand::class)
        ->fillForm(['name' => null, 'sort_order' => 0])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

it('cancels deletion of a brand that still has cars, without a database exception', function () {
    $brand = Brand::factory()->create();
    Car::factory()->for($brand)->create();

    livewire(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Brand::query()->whereKey($brand->id)->exists())->toBeTrue();
});

it('deletes a brand that has no cars', function () {
    $brand = Brand::factory()->create();

    livewire(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Brand::query()->whereKey($brand->id)->exists())->toBeFalse();
});
