<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CarPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Фотография автомобиля.
 *
 * Часть агрегата `Car` с собственным порядком вывода, а не запись
 * общей медиабиблиотеки: галерее нужна сортировка внутри карточки,
 * а не переиспользование между разделами.
 */
#[Fillable(['car_id', 'disk', 'path', 'thumb_path', 'alt', 'sort_order', 'width', 'height', 'watermarked_at'])]
final class CarPhoto extends Model
{
    /** @use HasFactory<CarPhotoFactory> */
    use HasFactory;

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Публичный URL файла. Не входит в `$appends`: сериализация списка
     * каталога не должна дёргать драйвер диска на каждое фото.
     */
    protected function url(): Attribute
    {
        return Attribute::get(
            fn (): string => Storage::disk($this->disk)->url($this->path),
        );
    }

    /**
     * URL превью с откатом на оригинал.
     *
     * `thumb_path` пуст, когда обработка не удалась или фотография залита
     * до вехи 3.4. Отдавать в этом случае `null` значит показать в списке
     * админки битую картинку — лучше тяжёлый оригинал, чем пустая рамка.
     *
     * Как и `url`, не входит в `$appends`.
     */
    protected function thumbUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->thumb_path === null
                ? $this->url
                : Storage::disk($this->disk)->url($this->thumb_path),
        );
    }

    protected static function booted(): void
    {
        // Чистить диск в редакторе значило бы забыть об этом в tinker
        // и в будущем импорте. Событие ловит все пути удаления, кроме
        // каскада БД — его перекрывает событие `deleting` на `Car`.
        self::deleted(static function (self $photo): void {
            // CarPhotoSeeder раскладывает файлы без повторов, но
            // полагаться на это в коде удаления нельзя: две записи,
            // указывающие на один файл, — обычное следствие ручной
            // правки или импорта.
            $stillReferenced = self::query()
                ->where('disk', $photo->disk)
                ->where('path', $photo->path)
                ->whereKeyNot($photo->getKey())
                ->exists();

            if ($stillReferenced) {
                return;
            }

            $disk = Storage::disk($photo->disk);

            foreach (array_filter([$photo->path, $photo->thumb_path]) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $disk->delete($path);

                Log::info('[CarPhoto] файл удалён с диска', [
                    'car_id' => $photo->car_id,
                    'disk' => $photo->disk,
                    'path' => $path,
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            // Размеры файла (веха 4.14). Nullable: у фотографий, залитых
            // до вехи, их нет, пока по ним не пройдёт `images:restamp`.
            'width' => 'integer',
            'height' => 'integer',
            'watermarked_at' => 'datetime',
        ];
    }
}
