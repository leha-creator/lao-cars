<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Результат сохранения изображения на диск.
 *
 * `thumbPath` равен `null`, когда обработка не удалась и файл сохранён
 * как есть: превью в этом случае не существует, и записывать в БД путь
 * к несуществующему файлу нельзя — шаблон получил бы битую ссылку.
 *
 * `width`, `height` и `watermarked` заведены вехой 4.14. Сервис их
 * ВОЗВРАЩАЕТ, а не пишет: про базу он не знает и знать не должен
 * (правило зависимостей `ARCHITECTURE.md`), поэтому отметку о штампе
 * и размеры кладут в БД те же вызывающие, что кладут `path` и `size`.
 *
 * Размеры равны `null`, когда файл не читается как изображение, — это
 * ровно тот случай, на котором срабатывает откат `storeOriginal()`.
 * `null`, а не нули: ноль пикселей — это утверждение о размере, и `img`
 * с `width="0"` схлопнется, вместо того чтобы не резервировать место.
 */
final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $thumbPath,
        public string $mime,
        public int $size,
        public ?int $width = null,
        public ?int $height = null,
        public bool $watermarked = false,
    ) {}
}
