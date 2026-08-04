<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Приведение загружаемых изображений к вебу: ресайз, WebP, превью.
 *
 * Исходники каталога — снимки по 3 МБ; список из 12 карточек на таких
 * файлах весит около 36 МБ. Сервис ужимает оригинал до `images.max_width`
 * без апскейла, конвертирует в WebP и кладёт рядом превью шириной
 * `images.thumb_width`.
 *
 * Сервис, а не фасад: `intervention/image-laravel` добавил бы зависимость
 * ради статического фасада, который в `ARCHITECTURE.md` и так нежелателен.
 * `ImageManager` приходит через конструктор — иначе драйвер не подменить
 * в тестах.
 */
final class ImageProcessor
{
    public function __construct(private readonly ImageManager $images) {}

    /**
     * Сохранить загруженный через форму файл.
     */
    public function store(TemporaryUploadedFile $file, string $disk, string $directory): StoredImage
    {
        return $this->storeFile(
            sourcePath: (string) $file->getRealPath(),
            disk: $disk,
            directory: $directory,
            originalName: $file->getClientOriginalName(),
        );
    }

    /**
     * Сохранить файл, уже лежащий на локальной файловой системе.
     *
     * Нужен сидам и будущему импорту: они работают с путями, а не
     * с загрузками Livewire, и обязаны проходить ровно ту же обработку —
     * иначе демо-данные окажутся тяжелее того, что заливает администратор.
     *
     * `$basename` (имя без расширения) задаётся вызывающим, когда путь
     * должен быть предсказуемым: сид проверяет по нему, обработан ли
     * файл, и со случайным именем не смог бы остаться идемпотентным.
     * По умолчанию имя случайное — у загрузок из админки исходные имена
     * повторяются («IMG_1760.PNG» с двух телефонов), и совпадение
     * затирало бы чужой файл.
     */
    public function storeFile(string $sourcePath, string $disk, string $directory, ?string $originalName = null, ?string $basename = null): StoredImage
    {
        $originalName ??= basename($sourcePath);
        $base = $basename ?? Str::lower(Str::random(8)).'-'.uniqid();
        $originalSize = @filesize($sourcePath) ?: 0;

        try {
            return $this->process($sourcePath, $disk, $directory, $base, $originalName, $originalSize);
        } catch (Throwable $e) {
            // Битый или экзотический файл не должен стоить администратору
            // ошибки 500: контент важнее оптимизации — тот же принцип, по
            // которому запись лида первична, а уведомление вторично.
            Log::error('Не удалось обработать изображение — сохранён оригинал', [
                'file' => $originalName,
                'disk' => $disk,
                'directory' => $directory,
                'exception' => $e->getMessage(),
            ]);

            return $this->storeOriginal($sourcePath, $disk, $directory, $base, $originalName);
        }
    }

    /**
     * Путь превью по пути оригинала: `cars/a.webp` → `cars/thumbs/a.webp`.
     *
     * Публичный, потому что состояние `FileUpload` — плоский массив путей,
     * места под `thumb_path` в нём нет. `Car::syncPhotos()` восстанавливает
     * превью этим правилом, и жить оно обязано в одном месте: разъехавшись
     * с раскладкой `store()`, превью галереи молча отвяжутся.
     */
    public function thumbPathFor(string $path): string
    {
        $directory = dirname($path);
        $thumbs = (string) config('images.thumbs_directory');

        return $directory === '.' || $directory === ''
            ? $thumbs.'/'.basename($path)
            : $directory.'/'.$thumbs.'/'.basename($path);
    }

    /**
     * Ресайз, конвертация и превью. Бросает при любом провале GD.
     */
    private function process(
        string $sourcePath,
        string $disk,
        string $directory,
        string $base,
        string $originalName,
        int $originalSize,
    ): StoredImage {
        $quality = (int) config('images.quality');

        $image = $this->images->read($sourcePath);

        // scaleDown, а не resize: апскейл запрещён — снимок 800px,
        // растянутый до 1920, только тяжелее и мылит.
        $image->scaleDown(width: (int) config('images.max_width'));
        $encoded = (string) $image->toWebp($quality);

        $path = $directory.'/'.$base.'.webp';
        Storage::disk($disk)->put($path, $encoded);

        // Превью строится из уже ужатого изображения: thumb_width заведомо
        // меньше max_width, второе чтение файла было бы лишним.
        $image->scaleDown(width: (int) config('images.thumb_width'));
        $thumbPath = $this->thumbPathFor($path);
        Storage::disk($disk)->put($thumbPath, (string) $image->toWebp($quality));

        $size = strlen($encoded);

        // GD держит распакованный растр: снимок 6000×4000 — это около
        // 96 МБ в памяти, и без явного освобождения несколько загрузок
        // подряд упираются в memory_limit. Сборщик мусора PHP до конца
        // запроса сюда может и не дойти.
        unset($image, $encoded);

        // INFO только при заметной экономии: логировать каждую загрузку
        // значит утопить в рутине те записи, ради которых лог и читают.
        if ($originalSize > 0 && $size * 2 < $originalSize) {
            Log::info('Изображение ужато', [
                'file' => $originalName,
                'path' => $path,
                'before' => $originalSize,
                'after' => $size,
            ]);
        }

        return new StoredImage(
            path: $path,
            thumbPath: $thumbPath,
            mime: 'image/webp',
            size: $size,
        );
    }

    /**
     * Откат: файл кладётся как есть, без превью.
     */
    private function storeOriginal(string $sourcePath, string $disk, string $directory, string $base, string $originalName): StoredImage
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $name = $extension === '' ? $base : $base.'.'.Str::lower($extension);

        $path = Storage::disk($disk)->putFileAs($directory, new File($sourcePath), $name);

        return new StoredImage(
            path: (string) $path,
            thumbPath: null,
            mime: Storage::disk($disk)->mimeType((string) $path) ?: 'application/octet-stream',
            size: (int) Storage::disk($disk)->size((string) $path),
        );
    }
}
