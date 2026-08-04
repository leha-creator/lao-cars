<?php

/*
 * Обработка изображений (веха 3.4).
 *
 * Тесты идут через `storeFile()`: `store()` — тонкая обёртка над ним
 * под загрузку Livewire, и она проверяется настоящей загрузкой
 * в MediaResourceTest. Собирать здесь TemporaryUploadedFile руками
 * значило бы проверять склейку Livewire, а не обработку.
 *
 * Отдельно проверяется соответствие `thumbPathFor()` и фактического
 * пути, куда `storeFile()` кладёт превью: именно на нём держится
 * восстановление `thumb_path` в `Car::syncPhotos()`. Разъехавшись,
 * они молча отвяжут превью всей галереи — удалять этот тест нельзя.
 */

use App\Services\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->processor = app(ImageProcessor::class);
});

/**
 * Ширина картинки, лежащей на фейковом диске.
 */
function storedWidth(string $path): int
{
    $image = imagecreatefromstring(Storage::disk('public')->get($path));

    return imagesx($image);
}

it('scales a wide image down to the configured limit', function () {
    // Пределы задаются маленькими намеренно: GD держит распакованный
    // растр, и картинки «как в бою» (2400×1600 — это ~15 МБ на каждую)
    // выносят memory_limit на полном прогоне. Проверяется правило,
    // а не конкретные 1920 пикселей.
    config()->set('images.max_width', 400);

    // Файл держится в переменной: без ссылки PHP освобождает временный
    // файл фейка ещё до вызова, и сервис получает несуществующий путь.
    $file = UploadedFile::fake()->image('wide.png', 1000, 600);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'wide.png');

    expect(storedWidth($stored->path))->toBe(400);
});

it('does not upscale a narrow image', function () {
    config()->set('images.max_width', 1920);

    $file = UploadedFile::fake()->image('narrow.png', 300, 200);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'narrow.png');

    // Растянутый до 1920 снимок 300px только тяжелее и мылит.
    expect(storedWidth($stored->path))->toBe(300);
});

it('converts to webp and puts a thumbnail in the thumbs subdirectory', function () {
    config()->set('images.max_width', 400);
    config()->set('images.thumb_width', 100);

    $file = UploadedFile::fake()->image('photo.png', 800, 600);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'photo.png');

    expect($stored->path)->toStartWith('cars/')->toEndWith('.webp')
        ->and($stored->thumbPath)->toStartWith('cars/thumbs/')->toEndWith('.webp')
        ->and($stored->mime)->toBe('image/webp')
        ->and($stored->size)->toBeGreaterThan(0);

    Storage::disk('public')->assertExists($stored->path);
    Storage::disk('public')->assertExists($stored->thumbPath);

    expect(storedWidth($stored->thumbPath))->toBe(100);
});

it('matches thumbPathFor() with the path storeFile() actually used', function () {
    $file = UploadedFile::fake()->image('photo.png', 300, 200);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'photo.png');

    // Car::syncPhotos() восстанавливает thumb_path вычислением, а не
    // хранением: если правило раскладки разъедется с этим методом,
    // превью галереи молча отвяжутся.
    expect($this->processor->thumbPathFor($stored->path))->toBe($stored->thumbPath);
});

it('keeps the original file and logs an error when processing fails', function () {
    Log::spy();

    $broken = UploadedFile::fake()->createWithContent('broken.png', 'это не изображение');

    $stored = $this->processor->storeFile($broken->getRealPath(), 'public', 'cars', 'broken.png');

    // Контент важнее оптимизации: битый файл не должен стоить
    // администратору ошибки 500.
    expect($stored->thumbPath)->toBeNull()
        ->and($stored->path)->toEndWith('.png');

    Storage::disk('public')->assertExists($stored->path);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Не удалось обработать изображение'))
        ->once();
});

it('builds the thumbnail path for a file in the disk root as well as in a directory', function () {
    config()->set('images.thumbs_directory', 'thumbs');

    expect($this->processor->thumbPathFor('a.webp'))->toBe('thumbs/a.webp')
        ->and($this->processor->thumbPathFor('cars/a.webp'))->toBe('cars/thumbs/a.webp');
});

it('stores under a caller-provided basename so seeders stay idempotent', function () {
    $file = UploadedFile::fake()->image('img_1760.png', 300, 200);

    $stored = $this->processor->storeFile(
        sourcePath: $file->getRealPath(),
        disk: 'public',
        directory: 'cars',
        originalName: 'img_1760.png',
        basename: 'img_1760',
    );

    expect($stored->path)->toBe('cars/img_1760.webp');
});
