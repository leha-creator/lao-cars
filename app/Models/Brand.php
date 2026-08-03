<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Марка автомобиля.
 *
 * Отдельная сущность, а не строка в `cars`, именно ради фильтра каталога:
 * список марок должен строиться выборкой из справочника, а не `DISTINCT`
 * по текстовой колонке, где опечатки наполнителя плодят фантомные марки.
 */
#[Fillable(['name', 'slug', 'sort_order'])]
#[RouteKey('slug')]
final class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    use HasSlug;

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    /**
     * Порядок вывода в фильтре: сначала заданный вручную, затем алфавит.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    protected function slugSource(): string
    {
        return (string) $this->name;
    }
}
