<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сотрудник в блоке «Команда» на странице «О компании».
 *
 * Контент сайта, а не учётная запись админки: пользователи панели
 * живут в `users` и с этой таблицей не связаны.
 */
#[Fillable(['name', 'position', 'bio', 'media_id', 'is_published', 'sort_order'])]
final class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * Фото из общей медиабиблиотеки.
     *
     * Связь односторонняя: удаление сотрудника изображение из библиотеки
     * не трогает — файл переиспользуемый. Обратное направление защищено
     * `nullOnDelete()` в миграции.
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * URL фото или `null`, если оно не назначено.
     *
     * Имя аксессора сохранено при переезде на медиабиблиотеку намеренно:
     * шаблоны страницы «О компании» (веха 4.5) читают `photo_url`,
     * и переучивать их из-за смены способа хранения незачем.
     *
     * Списки с превью обязаны грузиться с `with('media')` — иначе
     * обращение к связи даёт запрос на строку (антипаттерн N+1
     * из `ARCHITECTURE.md`).
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->media?->url,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
