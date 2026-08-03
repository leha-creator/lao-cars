<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceCategory;
use App\Models\Concerns\HasSlug;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Позиция того, что компания предлагает помимо автомобилей:
 * работы автосервиса, детейлинг, доп. сервисы и категории запчастей.
 *
 * Одна сущность на все категории — у всех позиций одинаковая форма
 * (название, описание, цена или «по запросу», порядок) и одно
 * назначение: привести к заявке. Различает их только категория.
 */
#[Fillable([
    'category',
    'title',
    'slug',
    'description',
    'price',
    'price_note',
    'is_published',
    'sort_order',
])]
#[RouteKey('slug')]
final class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use HasSlug;

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'source');
    }

    #[Scope]
    protected function inCategory(Builder $query, ServiceCategory $category): void
    {
        $query->where('category', $category);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'price' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return (string) $this->title;
    }
}
