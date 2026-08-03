<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CarAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Значение динамической характеристики у конкретного автомобиля.
 *
 * Хранится одной текстовой колонкой; тип, единица измерения и подписи
 * живут в строке справочника `CarAttribute`. Уникальный индекс
 * `(car_id, car_attribute_id)` гарантирует одно значение на пару —
 * две записи «Кузов» на одной карточке сетка показала бы обе.
 *
 * Пишется только через `Car::syncAttributeValues()`: там же лежат
 * правила нормализации, удаления пустого значения и проверки по
 * списку `options`.
 */
#[Fillable(['car_id', 'car_attribute_id', 'value'])]
final class CarAttributeValue extends Model
{
    /** @use HasFactory<CarAttributeValueFactory> */
    use HasFactory;

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Внешний ключ задан явно: Eloquent выводит его из имени метода
     * (`attribute` → `attribute_id`), а колонка называется
     * `car_attribute_id`. Без указания связь молча отдаёт `null`.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CarAttribute::class, 'car_attribute_id');
    }

    /**
     * Значение, приведённое к типу характеристики.
     *
     * Обращается к связи `attribute`. В списках это означает
     * `with('attributeValues.attribute')` на запросе: без предзагрузки
     * каждая строка сетки характеристик даёт отдельный запрос — ровно
     * тот N+1, что перечислен антипаттерном в ARCHITECTURE.md.
     */
    protected function casted(): Attribute
    {
        return Attribute::get(
            fn (): string|int|float|bool|null => $this->attribute->cast($this->value),
        );
    }

    /**
     * Готовая для вывода строка: «190 мм», «Да», «Седан».
     *
     * Как и `casted`, обращается к связи `attribute` и в списках
     * требует `with('attributeValues.attribute')`.
     */
    protected function formatted(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->attribute->format($this->value),
        );
    }
}
