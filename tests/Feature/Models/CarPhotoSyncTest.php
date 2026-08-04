<?php

/*
 * Синхронизация галереи автомобиля (веха 3.4).
 *
 * `Car::syncPhotos()` — единственный путь записи фотографий, и правила
 * порядка, сохранения ручного `alt` и удаления файлов живут только в нём.
 * Отдельно проверяется, что удаление автомобиля уносит файлы: каскад БД
 * событий Eloquent не поднимает, и без события `deleting` на `Car` файлы
 * остались бы на диске навсегда.
 */

use App\Models\Car;
use App\Models\CarPhoto;
use App\Services\ImageProcessor;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * Положить на фейковый диск оригинал и превью по правилу ImageProcessor.
 */
function putPhoto(string $path): string
{
    Storage::disk('public')->put($path, 'original');
    Storage::disk('public')->put(app(ImageProcessor::class)->thumbPathFor($path), 'thumb');

    return $path;
}

it('turns array order into sort_order', function () {
    $car = Car::factory()->create();

    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp'), putPhoto('cars/c.webp')]);

    expect($car->photos()->orderBy('sort_order')->pluck('path')->all())
        ->toBe(['cars/a.webp', 'cars/b.webp', 'cars/c.webp']);

    // Первое фото и есть главное — отдельного флага нет осознанно.
    expect($car->mainPhoto()->value('path'))->toBe('cars/a.webp');
});

it('restores thumb_path by the ImageProcessor rule when the file exists', function () {
    $car = Car::factory()->create();

    $car->syncPhotos([putPhoto('cars/a.webp')]);

    expect($car->photos()->value('thumb_path'))->toBe('cars/thumbs/a.webp');
});

it('leaves thumb_path null when the expected thumbnail is missing', function () {
    $car = Car::factory()->create();
    Storage::disk('public')->put('cars/lonely.png', 'original without a thumbnail');

    $car->syncPhotos(['cars/lonely.png']);

    // Так выглядит файл, у которого обработка сорвалась: писать путь
    // к несуществующему превью значит отдать шаблону битую ссылку.
    expect($car->photos()->value('thumb_path'))->toBeNull();
});

it('reorders without creating duplicate rows', function () {
    $car = Car::factory()->create();
    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp'), putPhoto('cars/c.webp')]);

    $car->syncPhotos(['cars/c.webp', 'cars/a.webp', 'cars/b.webp']);

    expect($car->photos()->count())->toBe(3)
        ->and($car->photos()->orderBy('sort_order')->pluck('path')->all())
        ->toBe(['cars/c.webp', 'cars/a.webp', 'cars/b.webp']);
});

it('keeps a manually set alt through a reorder', function () {
    $car = Car::factory()->create();
    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp')]);

    $car->photos()->where('path', 'cars/b.webp')->update(['alt' => 'Вид сзади']);
    $car->unsetRelation('photos');

    $car->syncPhotos(['cars/b.webp', 'cars/a.webp']);

    expect($car->photos()->where('path', 'cars/b.webp')->value('alt'))->toBe('Вид сзади');
});

it('generates alt from brand, model and position for new photos', function () {
    $car = Car::factory()->create(['model' => 'X5']);
    $car->load('brand');

    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp')]);

    expect($car->photos()->where('path', 'cars/b.webp')->value('alt'))
        ->toBe("{$car->brand->name} X5, фото 2");
});

it('deletes rows and files for paths dropped from the array', function () {
    $car = Car::factory()->create();
    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp')]);

    $car->syncPhotos(['cars/b.webp']);

    expect($car->photos()->count())->toBe(1);

    Storage::disk('public')->assertMissing('cars/a.webp');
    Storage::disk('public')->assertMissing('cars/thumbs/a.webp');
    Storage::disk('public')->assertExists('cars/b.webp');
    Storage::disk('public')->assertExists('cars/thumbs/b.webp');
});

it('keeps the file when another row still references the same path', function () {
    $first = Car::factory()->create();
    $second = Car::factory()->create();

    putPhoto('cars/shared.webp');
    $first->syncPhotos(['cars/shared.webp']);
    $second->syncPhotos(['cars/shared.webp']);

    $first->syncPhotos([]);

    // Сид раскладывает файлы без повторов, но полагаться на это в коде
    // удаления нельзя: две записи на один файл — обычное следствие
    // ручной правки или импорта.
    Storage::disk('public')->assertExists('cars/shared.webp');
    expect($second->photos()->count())->toBe(1);
});

it('deletes files of every photo when the car itself is deleted', function () {
    $car = Car::factory()->create();
    $car->syncPhotos([putPhoto('cars/a.webp'), putPhoto('cars/b.webp')]);

    $car->delete();

    // Каскад БД (`car_photos.car_id` объявлен cascadeOnDelete) событий
    // Eloquent не поднимает — без события `deleting` на Car файлы
    // остались бы на диске навсегда.
    expect(CarPhoto::query()->where('car_id', $car->id)->exists())->toBeFalse();

    Storage::disk('public')->assertMissing('cars/a.webp');
    Storage::disk('public')->assertMissing('cars/thumbs/a.webp');
    Storage::disk('public')->assertMissing('cars/b.webp');
    Storage::disk('public')->assertMissing('cars/thumbs/b.webp');
});
