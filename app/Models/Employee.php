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
use Illuminate\Support\Facades\Storage;

/**
 * Сотрудник в блоке «Команда» на странице «О компании».
 *
 * Контент сайта, а не учётная запись админки: пользователи панели
 * живут в `users` и с этой таблицей не связаны.
 */
#[Fillable(['name', 'position', 'bio', 'photo_path', 'is_published', 'sort_order'])]
final class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

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
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
