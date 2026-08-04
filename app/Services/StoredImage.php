<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Результат сохранения изображения на диск.
 *
 * `thumbPath` равен `null`, когда обработка не удалась и файл сохранён
 * как есть: превью в этом случае не существует, и записывать в БД путь
 * к несуществующему файлу нельзя — шаблон получил бы битую ссылку.
 */
final readonly class StoredImage
{
    public function __construct(
        public string $path,
        public ?string $thumbPath,
        public string $mime,
        public int $size,
    ) {}
}
