<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CarPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Фотография автомобиля.
 *
 * Часть агрегата `Car` с собственным порядком вывода, а не запись
 * общей медиабиблиотеки: галерее нужна сортировка внутри карточки,
 * а не переиспользование между разделами.
 */
#[Fillable(['car_id', 'disk', 'path', 'alt', 'sort_order'])]
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
