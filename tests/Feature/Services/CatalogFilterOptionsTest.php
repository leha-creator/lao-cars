<?php

use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Services\CatalogFilterOptions;
use Illuminate\Support\Facades\DB;

/*
 * Варианты формы фильтра (веха 3.6).
 *
 * Одно правило на весь файл: показывается только то, по чему что-то
 * найдётся. Вариант, дающий ноль автомобилей, — не «пустая выдача»,
 * а сломанное доверие к фильтру, и проданные карточки на состав опций
 * влиять обязаны.
 */

/**
 * @return array<string, mixed>
 */
function catalogOptions(): array
{
    return app(CatalogFilterOptions::class)->build();
}

it('hides a brand without available cars', function () {
    $zeekr = Brand::factory()->create(['name' => 'Zeekr']);
    $geely = Brand::factory()->create(['name' => 'Geely']);

    Car::factory()->for($zeekr)->create();
    Car::factory()->for($geely)->sold()->create();

    expect(catalogOptions()['brands']->pluck('name')->all())->toBe(['Zeekr']);
});

it('orders brands by the dictionary order', function () {
    $manual = Brand::factory()->create(['name' => 'Voyah', 'sort_order' => 1]);
    $alphabetFirst = Brand::factory()->create(['name' => 'BYD', 'sort_order' => 10]);
    $alphabetSecond = Brand::factory()->create(['name' => 'Chery', 'sort_order' => 10]);

    foreach ([$manual, $alphabetFirst, $alphabetSecond] as $brand) {
        Car::factory()->for($brand)->create();
    }

    // Сначала заданный вручную порядок, затем алфавит — так описан
    // скоуп Brand::ordered().
    expect(catalogOptions()['brands']->pluck('name')->all())->toBe(['Voyah', 'BYD', 'Chery']);
});

it('hides an engine type that is absent from the available cars', function () {
    Car::factory()->create(['engine_type' => EngineType::Electric]);
    Car::factory()->sold()->create(['engine_type' => EngineType::Diesel]);

    expect(catalogOptions()['engines'])->toBe([EngineType::Electric]);
});

it('orders engine types by the enum case order', function () {
    // Порядок берётся из enum-а, а не из выдачи БД: DISTINCT порядок
    // не хранит, и список менялся бы местами от показа к показу.
    Car::factory()->create(['engine_type' => EngineType::Electric]);
    Car::factory()->create(['engine_type' => EngineType::Petrol]);
    Car::factory()->create(['engine_type' => EngineType::Hybrid]);

    expect(catalogOptions()['engines'])
        ->toBe([EngineType::Petrol, EngineType::Hybrid, EngineType::Electric]);
});

it('computes year and price bounds from available cars only', function () {
    Car::factory()->create(['year' => 2022, 'price' => 3_000_000]);
    Car::factory()->create(['year' => 2025, 'price' => 7_000_000]);
    // Проданный автомобиль раздвинул бы обе границы — и обещал бы
    // диапазон, в котором ничего нет.
    Car::factory()->sold()->create(['year' => 2010, 'price' => 500_000]);

    $options = catalogOptions();

    expect($options['years'])->toBe(['min' => 2022, 'max' => 2025])
        ->and($options['prices'])->toBe(['min' => 3_000_000, 'max' => 7_000_000]);
});

it('returns null bounds for an empty catalog', function () {
    Car::factory()->sold()->create();

    $options = catalogOptions();

    expect($options['years'])->toBe(['min' => null, 'max' => null])
        ->and($options['prices'])->toBe(['min' => null, 'max' => null]);
});

it('takes attribute values from the dictionary and keeps its order', function () {
    CarAttribute::factory()
        ->select(['Седан', 'Хэтчбек', 'Кроссовер', 'Внедорожник'])
        ->inFilter()
        ->create(['key' => 'body_type', 'label' => 'Кузов']);

    // Значения заводятся в порядке, обратном справочному: порядок обязан
    // прийти из справочника, а не из того, как строки легли в таблицу.
    foreach (['Внедорожник', 'Кроссовер', 'Седан'] as $value) {
        Car::factory()->create()->syncAttributeValues(['body_type' => $value]);
    }

    $attributes = catalogOptions()['attributes'];

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]['attribute']->key)->toBe('body_type')
        // «Хэтчбек» есть в справочнике, но ни у одного автомобиля —
        // в фильтре ему делать нечего.
        ->and(array_column($attributes[0]['values'], 'value'))
        ->toBe(['Седан', 'Кроссовер', 'Внедорожник']);
});

it('labels a boolean attribute with the dictionary wording', function () {
    CarAttribute::factory()->boolean()->inFilter()->create(['key' => 'customs_cleared']);

    Car::factory()->create()->syncAttributeValues(['customs_cleared' => true]);
    Car::factory()->create()->syncAttributeValues(['customs_cleared' => false]);

    // Словарь «Да / Нет» живёт в CarAttribute::format() и больше нигде.
    expect(catalogOptions()['attributes'][0]['values'])->toBe([
        ['value' => '1', 'label' => 'Да'],
        ['value' => '0', 'label' => 'Нет'],
    ]);
});

it('shows only the boolean value that actually occurs', function () {
    CarAttribute::factory()->boolean()->inFilter()->create(['key' => 'customs_cleared']);

    Car::factory()->create()->syncAttributeValues(['customs_cleared' => true]);

    expect(catalogOptions()['attributes'][0]['values'])->toBe([
        ['value' => '1', 'label' => 'Да'],
    ]);
});

it('counts attribute values by available cars only', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inFilter()->create(['key' => 'body_type']);

    Car::factory()->create()->syncAttributeValues(['body_type' => 'Седан']);
    Car::factory()->sold()->create()->syncAttributeValues(['body_type' => 'Кроссовер']);

    expect(array_column(catalogOptions()['attributes'][0]['values'], 'value'))->toBe(['Седан']);
});

it('hides an attribute without a single occurring value', function () {
    CarAttribute::factory()->select(['Седан'])->inFilter()->create(['key' => 'body_type']);
    Car::factory()->create();

    // Пустой селект в форме — сломанный контрол, а не «фильтр без
    // вариантов».
    expect(catalogOptions()['attributes'])->toBe([]);
});

it('hides an attribute that is not marked for the filter', function () {
    CarAttribute::factory()->select(['Седан'])->create(['key' => 'body_type', 'show_in_filter' => false]);
    Car::factory()->create()->syncAttributeValues(['body_type' => 'Седан']);

    expect(catalogOptions()['attributes'])->toBe([]);
});

it('hides an attribute of a non-filterable type even when the flag is set', function () {
    $attribute = CarAttribute::factory()->text()->create(['key' => 'trim']);

    // Противоречие в справочнике возникает только в обход событий
    // Eloquent — тем же массовым обновлением, что и в тестах фильтра.
    DB::table('car_attributes')->where('id', $attribute->id)->update(['show_in_filter' => true]);

    Car::factory()->create()->syncAttributeValues(['trim' => 'Long Range AWD']);

    expect(catalogOptions()['attributes'])->toBe([]);
});
