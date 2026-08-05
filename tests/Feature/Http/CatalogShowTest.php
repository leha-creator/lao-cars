<?php

use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;

/*
 * Карточка автомобиля (веха 3.6).
 *
 * Сетка характеристик, группы и доступность проданной карточки — всё,
 * на что ляжет вёрстка вехи 4.3 и микроразметка Vehicle.
 */

it('serves a car card by its slug', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);
    $car = Car::factory()->for($brand)->create(['model' => '001', 'year' => 2024]);

    // Привязка по slug работает через #[RouteKey('slug')] на модели.
    expect($car->slug)->toBe('zeekr-001-2024');

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Zeekr')
        ->assertSee('001');
});

it('returns 404 for an unknown slug', function () {
    $this->get('/catalog/no-such-car')->assertNotFound();
});

it('still serves a sold car', function () {
    $car = Car::factory()->sold()->create();

    // «Продан» — не удаление: карточка остаётся ради истории и SEO,
    // и ссылки из выдачи Google не должны превращаться в 404.
    $this->get('/catalog/'.$car->slug)->assertOk();
});

it('renders the attribute grid with group headers', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->inGroup('Кузов и салон')
        ->create(['key' => 'body_type', 'label' => 'Кузов', 'sort_order' => 10]);
    CarAttribute::factory()->boolean()->inGroup('Импорт')
        ->create(['key' => 'customs_cleared', 'label' => 'Растаможен', 'sort_order' => 20]);
    CarAttribute::factory()->text()
        ->create(['key' => 'trim', 'label' => 'Комплектация', 'sort_order' => 30]);

    $car = Car::factory()->create();
    $car->syncAttributeValues([
        'body_type' => 'Кроссовер',
        'customs_cleared' => true,
        'trim' => 'Long Range AWD',
    ]);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Кузов и салон')
        ->assertSee('Кроссовер')
        ->assertSee('Импорт')
        // Подпись булева значения приходит из CarAttribute::format().
        ->assertSee('Растаможен')
        ->assertSee('Да')
        // Характеристика без группы выводится блоком без заголовка.
        ->assertSee('Комплектация')
        ->assertSee('Long Range AWD');
});

it('hides an attribute that is not shown in the card', function () {
    CarAttribute::factory()->text()->hiddenInCard()
        ->create(['key' => 'internal_note', 'label' => 'Служебная заметка']);

    $car = Car::factory()->create();
    $car->syncAttributeValues(['internal_note' => 'Не для сайта']);

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertDontSee('Не для сайта');
});

it('serves a car without a single attribute value', function () {
    CarAttribute::factory()->select(['Седан'])->create(['key' => 'body_type']);

    $car = Car::factory()->create();

    $this->get('/catalog/'.$car->slug)->assertOk();
});

it('serves a car without a price', function () {
    $car = Car::factory()->withoutPrice()->create();

    $this->get('/catalog/'.$car->slug)
        ->assertOk()
        ->assertSee('Цена по запросу');
});

it('does not run a query per attribute row', function () {
    $brand = Brand::factory()->create();

    // Блок похожих должен заполняться у обеих карточек одинаково, иначе
    // разница в счётчике придёт от него, а не от сетки характеристик.
    Car::factory()->count(config('catalog.similar_limit'))->for($brand)->create(['price' => 5_000_000]);

    for ($i = 0; $i <= 9; $i++) {
        CarAttribute::factory()->text()->create(['key' => 'attr_'.$i, 'label' => 'Строка '.$i]);
    }

    $single = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    $single->syncAttributeValues(['attr_0' => 'значение']);

    $full = Car::factory()->for($brand)->create(['price' => 5_000_000]);
    $values = [];

    for ($i = 1; $i <= 9; $i++) {
        $values['attr_'.$i] = 'значение '.$i;
    }

    $full->syncAttributeValues($values);

    // Иначе первый из двух замеров включит промах кеша настроек,
    // который платят шапка и подвал, и разница в единицу сойдёт за N+1.
    warmSettingsCache();

    $singleQueries = countQueries(fn () => $this->get('/catalog/'.$single->slug)->assertOk());
    $fullQueries = countQueries(fn () => $this->get('/catalog/'.$full->slug)->assertOk());

    // Без with('attributeValues.attribute') каждая строка сетки давала бы
    // свой запрос — об этом прямо предупреждает PHPDoc cardAttributes().
    expect($singleQueries)->toBeGreaterThan(0)
        ->and($fullQueries)->toBe($singleQueries);
});
