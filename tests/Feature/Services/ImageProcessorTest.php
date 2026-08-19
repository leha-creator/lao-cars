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
use App\Support\ThumbnailPath;
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
    // хранением, и зовёт ThumbnailPath напрямую — модели по правилам
    // зависимостей нельзя знать о сервисе. Проверяются оба входа
    // в одно правило: разъехавшись с раскладкой store(), они молча
    // отвяжут превью всей галереи.
    expect(ThumbnailPath::for($stored->path))->toBe($stored->thumbPath)
        ->and($this->processor->thumbPathFor($stored->path))->toBe($stored->thumbPath);
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

/*
 * Вотермарка (веха 4.14).
 *
 * Проверяется ПО ПИКСЕЛЯМ, а не по факту «файл записан»: логотип может
 * не наложиться — не тем размером, не в тот угол, вовсе прозрачным, —
 * и файл при этом запишется как ни в чём не бывало.
 */

/**
 * Доля пикселей в правом нижнем углу, отличающихся от левого верхнего.
 *
 * Фон фейка `UploadedFile::fake()->image()` однотонный, поэтому любое
 * отличие в углу со штампом — это то, что нарисовала вотермарка.
 */
function cornerInk(string $path): float
{
    $image = imagecreatefromstring(Storage::disk('public')->get($path));
    $width = imagesx($image);
    $height = imagesy($image);
    $reference = imagecolorat($image, 2, 2);

    $box = 0;
    $different = 0;

    for ($x = (int) ($width * 0.6); $x < $width; $x++) {
        for ($y = (int) ($height * 0.6); $y < $height; $y++) {
            $box++;

            if (imagecolorat($image, $x, $y) !== $reference) {
                $different++;
            }
        }
    }

    return $box === 0 ? 0.0 : $different / $box;
}

/** Средняя яркость правого нижнего угла, 0…255. */
function cornerBrightness(string $path): float
{
    $image = imagecreatefromstring(Storage::disk('public')->get($path));
    $width = imagesx($image);
    $height = imagesy($image);

    $sum = 0;
    $count = 0;

    for ($x = (int) ($width * 0.6); $x < $width; $x++) {
        for ($y = (int) ($height * 0.6); $y < $height; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
            $count++;
        }
    }

    return $count === 0 ? 0.0 : $sum / $count / 3;
}

/** Белый кадр на диске — тот случай, ради которого заведена подложка. */
function whiteFrame(): string
{
    $gd = imagecreatetruecolor(1200, 800);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));
    $path = tempnam(sys_get_temp_dir(), 'white').'.png';
    imagepng($gd, $path);

    return $path;
}

it('stamps the original and lets the thumbnail inherit the stamp', function () {
    config()->set('images.max_width', 800);
    config()->set('images.thumb_width', 300);
    config()->set('images.watermark.min_width', 100);

    $file = UploadedFile::fake()->image('car.png', 1200, 800);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'car.png');

    // Штамп ставится ОДИН раз, до конвертации оригинала, и превью
    // наследует его масштабированием. Два наложения — два места, где
    // размер и положение могут разойтись, и разойдутся они молча.
    expect($stored->watermarked)->toBeTrue()
        ->and(cornerInk($stored->path))->toBeGreaterThan(0.01)
        ->and(cornerInk($stored->thumbPath))->toBeGreaterThan(0.01);
});

it('reports the real dimensions of the processed frame', function () {
    // Без них лайтбокс врёт: «открыть в полном размере» обязано знать,
    // есть ли у файла размер, которого не видно в карточке.
    config()->set('images.max_width', 800);

    $file = UploadedFile::fake()->image('car.png', 1200, 800);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'car.png');

    expect($stored->width)->toBe(800)
        ->and($stored->height)->toBe(533)
        ->and(storedWidth($stored->path))->toBe($stored->width);
});

it('leaves a frame clean when the caller asks for no watermark', function () {
    config()->set('images.max_width', 800);
    config()->set('images.watermark.min_width', 100);

    $file = UploadedFile::fake()->image('avatar.png', 1200, 800);

    $stored = $this->processor->storeFile(
        $file->getRealPath(), 'public', 'media', 'avatar.png', watermark: false,
    );

    // Логотип компании на портрете сотрудника — не забытая настройка,
    // а видимая ошибка, и чинится она только перезаливкой файла.
    expect($stored->watermarked)->toBeFalse()
        ->and(cornerInk($stored->path))->toBe(0.0);
});

it('does not stamp a frame narrower than the configured floor', function () {
    // На аватаре 200px штамп занял бы четверть площади.
    config()->set('images.max_width', 800);
    config()->set('images.watermark.min_width', 600);

    $file = UploadedFile::fake()->image('small.png', 400, 300);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'media', 'small.png');

    expect($stored->watermarked)->toBeFalse()
        ->and(cornerInk($stored->path))->toBe(0.0);
});

it('survives a missing watermark file, saves the image and warns', function () {
    Log::spy();
    config()->set('images.max_width', 800);
    config()->set('images.watermark.min_width', 100);
    config()->set('images.watermark.path', 'resources/images/does-not-exist.png');

    $file = UploadedFile::fake()->image('car.png', 1200, 800);

    $stored = $this->processor->storeFile($file->getRealPath(), 'public', 'cars', 'car.png');

    // Контент важнее оформления — тот же принцип, что у отката выше.
    expect($stored->watermarked)->toBeFalse();
    Storage::disk('public')->assertExists($stored->path);

    // Но и молчать нельзя: без записи сайт наберёт сотню фотографий
    // без логотипа, и заметит это тот, кто откроет каталог через месяц.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'вотермарки'))
        ->once();
});

it('keeps a white logo visible on a white frame only because of the backdrop', function () {
    // Главный сторож решения 8. `logo-white.png` белый, и на светлом
    // кадре — снег, белая машина, засвеченное небо — без подложки он
    // исчезает целиком. Штамп, которого не видно, штампом не является,
    // а проверить это можно только яркостью: доля изменённых пикселей
    // у белого на белом почти та же, что у подложки.
    config()->set('images.max_width', 800);
    config()->set('images.watermark.min_width', 100);

    $withBackdrop = $this->processor->storeFile(whiteFrame(), 'public', 'cars', 'a.png', 'a');

    config()->set('images.watermark.backdrop', false);
    $logoOnly = $this->processor->storeFile(whiteFrame(), 'public', 'cars', 'b.png', 'b');

    config()->set('images.watermark.enabled', false);
    $clean = $this->processor->storeFile(whiteFrame(), 'public', 'cars', 'c.png', 'c');

    $cleanBrightness = cornerBrightness($clean->path);

    // Белое на белом практически неотличимо от чистого кадра.
    expect(abs(cornerBrightness($logoOnly->path) - $cleanBrightness))->toBeLessThan(2.0)
        // С подложкой угол заметно темнее.
        ->and(cornerBrightness($withBackdrop->path))->toBeLessThan($cleanBrightness - 5);
});
