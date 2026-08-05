<?php

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarPhoto;
use Illuminate\Support\Facades\Log;

/*
 * Микроразметка карточки автомобиля (веха 4.3).
 *
 * Проверяется форма данных, а не подстроки HTML: разметка достаётся
 * из ответа регулярным выражением и разбирается json_decode. Тест
 * на подстроки прошёл бы и на сломанном JSON, и на ключе с null —
 * а именно эти две поломки валидатор Google и считает ошибками.
 *
 * Соответствие требованиям Google этот файл не проверяет и проверить
 * не может: обязательные поля и допустимые значения знает только
 * валидатор. Прогон через Rich Results Test входит в приёмку вехи.
 */

/**
 * Разметка страницы, разобранная из единственного тега JSON-LD.
 *
 * Тег обязан быть один: два скрипта с разными версиями одних и тех же
 * данных — классический способ получить в выдаче не то, что ожидалось.
 *
 * @return array<int, array<string, mixed>>
 */
function jsonLdFrom(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    expect($matches[1])->toHaveCount(1);

    return json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);
}

it('renders one tag with a car and a breadcrumb list', function () {
    $car = Car::factory()->create();

    $data = jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent());

    expect($data)->toHaveCount(2)
        ->and($data[0]['@type'])->toBe('Car')
        ->and($data[1]['@type'])->toBe('BreadcrumbList');
});

it('fills the required car fields', function () {
    $brand = Brand::factory()->create(['name' => 'Zeekr']);

    $car = Car::factory()->for($brand)->create([
        'model' => '001',
        'year' => 2024,
        'engine_type' => EngineType::Petrol,
        'engine_volume' => 2.0,
        'engine_power' => 211,
        'drive' => DriveType::Full,
        'mileage' => 12_000,
    ]);

    CarPhoto::factory()->count(2)->sequenced()->for($car)->create();

    $vehicle = jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent())[0];

    expect($vehicle['name'])->toBe('Zeekr 001, 2024')
        ->and($vehicle['url'])->toBe(route('catalog.show', $car))
        ->and($vehicle['brand']['name'])->toBe('Zeekr')
        ->and($vehicle['model'])->toBe('001')
        ->and($vehicle['vehicleModelDate'])->toBe('2024')
        // В разметку уходят все фотографии, а не только главная.
        ->and($vehicle['image'])->toHaveCount(2)
        ->and($vehicle['fuelType'])->toBe('Бензин')
        ->and($vehicle['vehicleEngine']['engineDisplacement']['unitCode'])->toBe('LTR')
        ->and($vehicle['vehicleEngine']['enginePower']['value'])->toBe(211)
        ->and($vehicle['driveWheelConfiguration'])->toBe('https://schema.org/AllWheelDriveConfiguration')
        // Без единицы число читается как мили: у части агрегатов
        // это умолчание.
        ->and($vehicle['mileageFromOdometer']['unitCode'])->toBe('KMT')
        ->and($vehicle['mileageFromOdometer']['value'])->toBe(12_000);
});

it('takes the body type from the dynamic attribute and omits the key without it', function () {
    CarAttribute::factory()->select(['Седан', 'Кроссовер'])->create([
        'key' => 'body_type',
        'label' => 'Кузов',
    ]);

    $withBody = Car::factory()->create();
    $withBody->syncAttributeValues(['body_type' => 'Кроссовер']);

    $without = Car::factory()->create();

    expect(jsonLdFrom($this->get('/catalog/'.$withBody->slug)->assertOk()->getContent())[0])
        ->toHaveKey('bodyType')
        ->and(jsonLdFrom($this->get('/catalog/'.$withBody->slug)->getContent())[0]['bodyType'])
        ->toBe('Кроссовер');

    // Ключ отсутствует, а не равен null: ключ с null валидаторы читают
    // как «поле есть и пустое».
    expect(jsonLdFrom($this->get('/catalog/'.$without->slug)->assertOk()->getContent())[0])
        ->not->toHaveKey('bodyType');
});

it('does not offer a car without a price', function () {
    $car = Car::factory()->withoutPrice()->create();

    // Offer без price невалиден, а выдуманный ноль означает «отдаём
    // даром» — и именно так его прочитает агрегатор.
    expect(jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent())[0])
        ->not->toHaveKey('offers');
});

it('maps the status to schema.org availability', function (CarStatus $status, string $availability) {
    $car = Car::factory()->create(['status' => $status, 'price' => 3_000_000]);

    $vehicle = jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent())[0];

    expect($vehicle['offers']['availability'])->toBe($availability)
        ->and($vehicle['offers']['price'])->toBe('3000000')
        ->and($vehicle['offers']['priceCurrency'])->toBe('RUB');
})->with([
    [CarStatus::InStock, 'https://schema.org/InStock'],
    [CarStatus::OnOrder, 'https://schema.org/PreOrder'],
    [CarStatus::Sold, 'https://schema.org/SoldOut'],
]);

it('derives the item condition from the mileage', function () {
    // Пробег null — это не «ноль километров», а автомобиль под заказ,
    // у которого пробега нет вовсе (комментарий миграции вехи 3.2).
    $new = Car::factory()->create(['mileage' => null]);
    $used = Car::factory()->create(['mileage' => 40_000]);

    expect(jsonLdFrom($this->get('/catalog/'.$new->slug)->assertOk()->getContent())[0]['itemCondition'])
        ->toBe('https://schema.org/NewCondition')
        ->and(jsonLdFrom($this->get('/catalog/'.$used->slug)->assertOk()->getContent())[0]['itemCondition'])
        ->toBe('https://schema.org/UsedCondition');
});

it('escapes a closing script tag inside the description', function () {
    $payload = '</script><script>alert(1)</script>';

    $car = Car::factory()->create(['description' => 'Отличное авто. '.$payload]);

    $content = $this->get('/catalog/'.$car->slug)->assertOk()->getContent();

    // Без JSON_HEX_TAG эта строка закрыла бы тег по-настоящему
    // и превратила остаток страницы в разметку. Сторож нужен именно
    // здесь: без него флаг уберут при первом же рефакторинге
    // как «лишний».
    expect($content)->not->toContain($payload);

    // jsonLdFrom сам проверяет, что тег остался один, а json_decode —
    // что содержимое не порвано.
    expect(jsonLdFrom($content)[0]['description'])->toContain($payload);
});

it('describes the breadcrumbs with two positions', function () {
    $car = Car::factory()->create();

    $breadcrumbs = jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent())[1];

    expect($breadcrumbs['itemListElement'])->toHaveCount(2)
        ->and($breadcrumbs['itemListElement'][0]['position'])->toBe(1)
        ->and($breadcrumbs['itemListElement'][0]['item'])->toBe(route('catalog.index'))
        ->and($breadcrumbs['itemListElement'][1]['position'])->toBe(2)
        ->and($breadcrumbs['itemListElement'][1]['item'])->toBe(route('catalog.show', $car));
});

it('warns and drops the image key for a car without photos', function () {
    $car = Car::factory()->create();

    Log::spy();

    $vehicle = jsonLdFrom($this->get('/catalog/'.$car->slug)->assertOk()->getContent())[0];

    // Последствие за пределами внешнего вида: карточка перестаёт быть
    // кандидатом на расширенный сниппет.
    expect($vehicle)->not->toHaveKey('image');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'нет ни одного фото')
            && $context['car_id'] === $car->id,
    );
});
