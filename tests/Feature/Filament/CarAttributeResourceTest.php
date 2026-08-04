<?php

/*
 * CRUD справочника динамических характеристик (веха 3.4).
 *
 * Главное здесь — неизменяемость `key`: он публичный контракт, по нему
 * строится GET-параметр фильтра каталога (веха 3.6) и обращение из
 * шаблона карточки (веха 4.3).
 */

use App\Enums\CarAttributeType;
use App\Filament\Resources\CarAttributes\Pages\CreateCarAttribute;
use App\Filament\Resources\CarAttributes\Pages\EditCarAttribute;
use App\Filament\Resources\CarAttributes\Pages\ListCarAttributes;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use App\Models\User;
use Filament\Actions\DeleteAction;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows attributes in the list', function () {
    $attributes = CarAttribute::factory()->count(3)->create();

    livewire(ListCarAttributes::class)
        ->assertOk()
        ->assertCanSeeTableRecords($attributes);
});

it('creates a text attribute', function () {
    livewire(CreateCarAttribute::class)
        ->fillForm([
            'key' => 'body_type',
            'label' => 'Кузов',
            'type' => CarAttributeType::Text->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('car_attributes', [
        'key' => 'body_type',
        'label' => 'Кузов',
        'type' => CarAttributeType::Text->value,
    ]);
});

it('rejects a key that is not lowercase snake_case latin', function (string $key) {
    livewire(CreateCarAttribute::class)
        ->fillForm([
            'key' => $key,
            'label' => 'Кузов',
            'type' => CarAttributeType::Text->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['key']);
})->with([
    'кириллица' => 'кузов',
    'заглавные' => 'BodyType',
    'начинается с цифры' => '1body',
    'дефис вместо подчёркивания' => 'body-type',
]);

it('rejects a duplicate key', function () {
    CarAttribute::factory()->create(['key' => 'body_type']);

    livewire(CreateCarAttribute::class)
        ->fillForm([
            'key' => 'body_type',
            'label' => 'Кузов',
            'type' => CarAttributeType::Text->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['key']);
});

it('requires a label', function () {
    livewire(CreateCarAttribute::class)
        ->fillForm([
            'key' => 'body_type',
            'label' => null,
            'type' => CarAttributeType::Text->value,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['label']);
});

it('keeps the key unchanged when the attribute is saved from the edit form', function () {
    $attribute = CarAttribute::factory()->create([
        'key' => 'body_type',
        'label' => 'Кузов',
    ]);

    livewire(EditCarAttribute::class, ['record' => $attribute->getRouteKey()])
        // Поле объявлено `disabledOn('edit')` и не дегидрируется:
        // отправленное значение до модели дойти не должно.
        ->fillForm(['key' => 'hacked_key', 'label' => 'Тип кузова'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($attribute->refresh())
        ->key->toBe('body_type')
        ->label->toBe('Тип кузова');
});

it('deletes an attribute together with its values', function () {
    $attribute = CarAttribute::factory()->create();
    $car = Car::factory()->create();
    $car->syncAttributeValues([$attribute->key => 'Седан']);

    expect($attribute->values()->count())->toBe(1);

    livewire(EditCarAttribute::class, ['record' => $attribute->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(CarAttribute::query()->whereKey($attribute->id)->exists())->toBeFalse()
        ->and(CarAttributeValue::query()->where('car_attribute_id', $attribute->id)->exists())->toBeFalse();
});
