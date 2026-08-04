<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Правило соответствия «оригинал → превью»: `cars/a.webp` → `cars/thumbs/a.webp`.
 *
 * Живёт отдельно от `ImageProcessor` не ради красоты, а по правилу
 * зависимостей из `ARCHITECTURE.md`: модель не знает о сервисах, а
 * `Car::syncPhotos()` обязана восстанавливать `thumb_path` тем же
 * правилом, по которому сервис его раскладывает. Разъехавшись, они
 * молча отвяжут превью всей галереи.
 *
 * Это чистая функция над путём и конфигом — ни диска, ни внешнего мира,
 * поэтому обращение к ней из модели слоёв не нарушает.
 */
final class ThumbnailPath
{
    public static function for(string $path): string
    {
        $directory = dirname($path);
        $thumbs = (string) config('images.thumbs_directory');

        return $directory === '.' || $directory === ''
            ? $thumbs.'/'.basename($path)
            : $directory.'/'.$thumbs.'/'.basename($path);
    }
}
