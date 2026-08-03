<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Concerns\HasSlug;
use Database\Factories\CarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Автомобиль каталога.
 *
 * В колонках лежат только фиксированные фильтруемые характеристики
 * (раздел 3.2 ТЗ). Всё остальное — «кузов», «цвет», «растаможен» —
 * живёт в справочнике динамических характеристик (веха 3.3).
 */
#[Fillable([
    'brand_id',
    'model',
    'slug',
    'year',
    'engine_type',
    'engine_volume',
    'engine_power',
    'drive',
    'mileage',
    'price',
    'status',
    'show_on_homepage',
    'history',
    'description',
    'meta_title',
    'meta_description',
    'sort_order',
])]
#[RouteKey('slug')]
final class Car extends Model
{
    /** @use HasFactory<CarFactory> */
    use HasFactory;

    use HasSlug;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(CarPhoto::class)->orderBy('sort_order');
    }

    /**
     * Главное фото — первое по порядку. Отдельного флага нет осознанно:
     * флаг и порядок неизбежно расходятся, и администратор перетаскивает
     * фото, не понимая, почему на карточке осталось старое.
     */
    public function mainPhoto(): HasOne
    {
        return $this->hasOne(CarPhoto::class)->oldestOfMany('sort_order');
    }

    public function leads(): MorphMany
    {
        return $this->morphMany(Lead::class, 'source');
    }

    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->where('status', CarStatus::InStock);
    }

    #[Scope]
    protected function onOrder(Builder $query): void
    {
        $query->where('status', CarStatus::OnOrder);
    }

    /**
     * Всё, что можно купить. Проданные карточки остаются в базе ради
     * истории и SEO, но из выдачи каталога уходят.
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereIn('status', [CarStatus::InStock, CarStatus::OnOrder]);
    }

    #[Scope]
    protected function onHomepage(Builder $query): void
    {
        $query->where('show_on_homepage', true)->orderBy('sort_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'engine_type' => EngineType::class,
            'engine_volume' => 'decimal:1',
            'engine_power' => 'integer',
            'drive' => DriveType::class,
            'mileage' => 'integer',
            'price' => 'integer',
            'status' => CarStatus::class,
            'show_on_homepage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function slugSource(): string
    {
        return trim(sprintf('%s %s %s', $this->brand?->name ?? '', $this->model, $this->year));
    }
}
