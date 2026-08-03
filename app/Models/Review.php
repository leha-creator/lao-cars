<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Отзыв клиента с модерацией публикации (раздел 3.4 ТЗ).
 */
#[Fillable([
    'author_name',
    'author_context',
    'body',
    'rating',
    'photo_path',
    'is_published',
    'published_at',
    'sort_order',
])]
final class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * Только прошедшие модерацию. Публичные выборки обязаны идти
     * через этот скоуп — немодерированный отзыв на сайте это дефект.
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('is_published', false);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderByDesc('published_at');
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => filled($this->photo_path)
                ? Storage::disk('public')->url($this->photo_path)
                : null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
