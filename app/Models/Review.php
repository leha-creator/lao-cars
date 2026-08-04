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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отзыв клиента с модерацией публикации (раздел 3.4 ТЗ).
 */
#[Fillable([
    'author_name',
    'author_context',
    'body',
    'rating',
    'media_id',
    'is_published',
    'published_at',
    'sort_order',
])]
final class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * Фото автора из общей медиабиблиотеки.
     *
     * @see Employee::media() — та же связь и та же причина
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

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

    /**
     * URL фото автора или `null`, если оно не назначено.
     *
     * Имя аксессора сохранено при переезде на медиабиблиотеку намеренно —
     * см. `Employee::photoUrl()`.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->media?->url,
        );
    }

    /**
     * Дата первой публикации проставляется моделью, а не формой.
     *
     * Публиковать можно из формы, из действия в списке и из tinker —
     * три копии правила разъедутся. Снятие публикации дату намеренно
     * не трогает: это факт («когда отзыв впервые увидел сайт»), а не
     * флаг, и по нему сортирует `scopeOrdered()`. Обнулять её от снятой
     * галочки значит терять порядок отзывов при первой же перемодерации.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $review): void {
            if ($review->is_published && $review->published_at === null) {
                $review->published_at = now();
            }
        });
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
