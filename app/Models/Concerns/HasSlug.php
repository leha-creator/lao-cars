<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Генерация slug при создании записи, если он не задан явно.
 *
 * Slug — часть публичного URL и уникальный индекс в БД, поэтому
 * коллизии разруливаются здесь, а не падают ошибкой в лицо
 * администратору: одинаковые марка + модель + год в каталоге —
 * обычное дело (разные комплектации одного автомобиля).
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->slug)) {
                $model->slug = $model->generateUniqueSlug();
            }
        });
    }

    /**
     * Текст, из которого строится slug.
     */
    abstract protected function slugSource(): string;

    protected function generateUniqueSlug(): string
    {
        // Str::slug транслитерирует кириллицу, поэтому русские названия
        // услуг дают читаемый латинский slug, а не пустую строку.
        $base = Str::slug($this->slugSource());

        if ($base === '') {
            $base = 'n-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
