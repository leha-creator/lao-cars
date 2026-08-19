<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ThumbnailPath;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
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
    public function store(TemporaryUploadedFile $file, string $disk, string $directory, bool $watermark = true): StoredImage
    {
        return $this->storeFile(
            sourcePath: (string) $file->getRealPath(),
            disk: $disk,
            directory: $directory,
            originalName: $file->getClientOriginalName(),
            watermark: $watermark,
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
     *
     * `$watermark` по умолчанию `true`, потому что это верно для всех
     * вызовов, кроме служебных изображений: портретов сотрудников,
     * аватаров отзывов, фонов и иллюстраций этапов. Логотип компании
     * на портрете менеджера — это не забытая настройка, а видимая ошибка,
     * поэтому исключения перечисляются явно у места вызова.
     */
    public function storeFile(string $sourcePath, string $disk, string $directory, ?string $originalName = null, ?string $basename = null, bool $watermark = true): StoredImage
    {
        $originalName ??= basename($sourcePath);
        $base = $basename ?? Str::lower(Str::random(8)).'-'.uniqid();
        $originalSize = @filesize($sourcePath) ?: 0;

        try {
            return $this->process($sourcePath, $disk, $directory, $base, $originalName, $originalSize, $watermark);
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
     * Само правило живёт в `ThumbnailPath`, а не здесь: `Car::syncPhotos()`
     * восстанавливает `thumb_path` вычислением, а модели по правилам
     * зависимостей нельзя знать о сервисах. Метод остаётся как удобный
     * фасад для тех, у кого сервис уже под рукой.
     */
    public function thumbPathFor(string $path): string
    {
        return ThumbnailPath::for($path);
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
        bool $watermark,
    ): StoredImage {
        $quality = (int) config('images.quality');

        $image = $this->images->read($sourcePath);

        // scaleDown, а не resize: апскейл запрещён — снимок 800px,
        // растянутый до 1920, только тяжелее и мылит.
        $image->scaleDown(width: (int) config('images.max_width'));

        // Штамп ставится ЗДЕСЬ — после ресайза и до конвертации оригинала:
        // превью ниже строится масштабированием этого же объекта и
        // наследует логотип вместе с ним. Обоснование — в config/images.php.
        $stamped = $watermark && $this->watermark($image);

        $encoded = (string) $image->toWebp($quality);

        // Размеры снимаются после ресайза и до освобождения растра: это
        // те самые числа, что уедут в `width`/`height` тега `img`.
        $width = $image->width();
        $height = $image->height();

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
            width: $width,
            height: $height,
            watermarked: $stamped,
        );
    }

    /**
     * Вжечь логотип в угол кадра. Возвращает «штамп поставлен».
     *
     * Отсутствующий или нечитаемый файл штампа НЕ РОНЯЕТ ЗАГРУЗКУ: тот же
     * принцип, что у отката в `storeFile()` — контент важнее оформления.
     * Но и молчать нельзя: без записи в лог сайт наберёт сотню фотографий
     * без логотипа, и заметит это тот, кто откроет каталог через месяц.
     */
    private function watermark(ImageInterface $image): bool
    {
        if (! config('images.watermark.enabled')) {
            return false;
        }

        // Кадр уже порога не штампуется вовсе: на аватаре 200px логотип
        // занял бы четверть площади.
        if ($image->width() < (int) config('images.watermark.min_width')) {
            return false;
        }

        $file = base_path((string) config('images.watermark.path'));

        if (! is_file($file) || ! is_readable($file)) {
            Log::warning('Файл вотермарки не найден — изображение сохранено без логотипа', [
                'path' => $file,
            ]);

            return false;
        }

        try {
            $margin = (int) round($image->width() * (float) config('images.watermark.margin_ratio'));

            $image->place(
                element: $this->watermarkElement($file, $image->width()),
                position: (string) config('images.watermark.position'),
                offset_x: $margin,
                offset_y: $margin,
                opacity: (int) config('images.watermark.opacity'),
            );
        } catch (Throwable $e) {
            Log::warning('Не удалось наложить вотермарку — изображение сохранено без логотипа', [
                'path' => $file,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Логотип, при необходимости на тёмной подложке.
     *
     * Подложка рисуется НЕ на самом кадре, а под логотипом внутри одного
     * элемента, который целиком и ставится в угол. Иначе положение
     * прямоугольника и положение логотипа считались бы в двух местах —
     * и разошлись бы молча на первом же кадре с другими пропорциями.
     *
     * Нужна она из-за самого файла: `logo-white.png` белый, и на светлом
     * кадре — снег, белая машина, засвеченное небо — исчезает целиком.
     */
    private function watermarkElement(string $file, int $frameWidth): ImageInterface
    {
        $logo = $this->images->read($file);
        $logo->scale(width: max(1, (int) round($frameWidth * (float) config('images.watermark.width_ratio'))));

        if (! config('images.watermark.backdrop')) {
            return $logo;
        }

        // Поле вокруг логотипа — доля от него самого, а не от кадра:
        // подложка обязана быть пропорциональна тому, что подкладывает.
        $padding = max(2, (int) round($logo->width() * 0.12));
        $width = $logo->width() + $padding * 2;
        $height = $logo->height() + $padding * 2;

        $alpha = max(0, min(100, (int) config('images.watermark.backdrop_opacity'))) / 100;

        $backdrop = $this->images->create($width, $height);
        $backdrop->drawRectangle(0, 0, function ($rectangle) use ($width, $height, $alpha): void {
            $rectangle->size($width, $height);
            $rectangle->background('rgba(0, 0, 0, '.$alpha.')');
        });

        $backdrop->place($logo, 'center');

        return $backdrop;
    }

    /**
     * Откат: файл кладётся как есть, без превью.
     */
    private function storeOriginal(string $sourcePath, string $disk, string $directory, string $base, string $originalName): StoredImage
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $name = $extension === '' ? $base : $base.'.'.Str::lower($extension);

        $path = Storage::disk($disk)->putFileAs($directory, new File($sourcePath), $name);

        // Размеры читаются с исходного файла штатной функцией PHP, а не
        // через Intervention: сюда попадают ровно те файлы, на которых
        // Intervention уже споткнулся, и второй заход тем же инструментом
        // дал бы то же исключение. `getimagesize()` не разворачивает
        // растр — она читает заголовок, поэтому и не падает там, где
        // падает декодер. Не прочиталось — `null`, а не выдуманные нули.
        $dimensions = @getimagesize($sourcePath);

        return new StoredImage(
            path: (string) $path,
            thumbPath: null,
            mime: Storage::disk($disk)->mimeType((string) $path) ?: 'application/octet-stream',
            size: (int) Storage::disk($disk)->size((string) $path),
            width: is_array($dimensions) ? $dimensions[0] : null,
            height: is_array($dimensions) ? $dimensions[1] : null,
            // Обработка не состоялась — значит, и штампа на файле нет.
            watermarked: false,
        );
    }
}
