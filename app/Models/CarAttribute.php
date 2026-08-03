<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarAttributeType;
use Database\Factories\CarAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Справочник динамических характеристик автомобиля (веха 3.3).
 *
 * Всё, что не стало колонкой `cars`, описывается здесь: «кузов»,
 * «цвет», «растаможен». Строка справочника задаёт тип значения,
 * единицу измерения, группу в сетке карточки и два флага вывода —
 * в карточке и в фильтре каталога.
 *
 * Значения хранит `CarAttributeValue` одной текстовой колонкой; тип
 * применяется на чтении методами `cast()` и `format()`.
 */
#[Fillable([
    'key',
    'label',
    'type',
    'unit',
    'group',
    'options',
    'sort_order',
    'show_in_card',
    'show_in_filter',
])]
final class CarAttribute extends Model
{
    /** @use HasFactory<CarAttributeFactory> */
    use HasFactory;

    public function values(): HasMany
    {
        return $this->hasMany(CarAttributeValue::class);
    }

    /**
     * Привести хранимую строку к типу характеристики.
     */
    public function cast(?string $value): string|int|float|bool|null
    {
        return $this->type->cast($value);
    }

    /**
     * Готовая для вывода строка: «190 мм», «Да», «Седан».
     *
     * Подписи «Да» и «Нет» живут здесь и больше нигде — по тому же
     * правилу, по которому подписи enum-ов живут в `label()`: копия
     * словаря в каждом шаблоне неизбежно разъезжается с оригиналом.
     */
    public function format(?string $value): ?string
    {
        $casted = $this->cast($value);

        if ($casted === null) {
            return null;
        }

        if ($this->type === CarAttributeType::Boolean) {
            return $casted ? 'Да' : 'Нет';
        }

        $formatted = (string) $casted;

        if ($this->type === CarAttributeType::Number && $this->unit !== null && $this->unit !== '') {
            return "{$formatted} {$this->unit}";
        }

        return $formatted;
    }

    /**
     * Допустимо ли значение для этой характеристики.
     *
     * Для `select` — только вариант из `options`: иначе в фильтр
     * каталога (веха 3.6) попадает значение, по которому ничего не
     * находится. Справочник без заполненного списка не принимает
     * ничего — это ошибка настройки, а не разрешение писать что угодно.
     */
    public function isValidValue(string $value): bool
    {
        if (! $this->type->hasOptions()) {
            return true;
        }

        return in_array($value, $this->options ?? [], strict: true);
    }

    #[Scope]
    protected function inCard(Builder $query): void
    {
        $query->where('show_in_card', true);
    }

    #[Scope]
    protected function inFilter(Builder $query): void
    {
        $query->where('show_in_filter', true);
    }

    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('label');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CarAttributeType::class,
            'options' => 'array',
            'sort_order' => 'integer',
            'show_in_card' => 'boolean',
            'show_in_filter' => 'boolean',
        ];
    }
}
