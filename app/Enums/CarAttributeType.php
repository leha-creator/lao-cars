<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;

/**
 * Тип динамической характеристики автомобиля (веха 3.3).
 *
 * Значение характеристики хранится одной текстовой колонкой, а тип
 * живёт здесь: типизированные колонки под каждый вариант дали бы три
 * nullable-поля, из которых два всегда пусты, и ветвление «куда писать»
 * в модели, фабрике, сиде, редакторе админки и фильтре каталога.
 * Приведение к типу делается на чтении методом `cast()`.
 */
enum CarAttributeType: string
{
    use HasLabels;

    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Текст',
            self::Number => 'Число',
            self::Boolean => 'Да / Нет',
            self::Select => 'Выбор из списка',
        };
    }

    /**
     * Привести хранимую строку к типу характеристики.
     *
     * Число возвращается целым, если точки в строке нет: иначе «4 двери»
     * выводились бы как «4.0 двери». Десятичный разделитель — только
     * точка; запятую нормализует форма на входе (веха 3.4), потому что
     * `(float) '2,5'` в PHP молча даёт `2.0`.
     */
    public function cast(?string $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Number => str_contains($value, '.') ? (float) $value : (int) $value,
            // Строгое сравнение с '1': всё остальное, включая пустую
            // строку и '0', — «Нет».
            self::Boolean => $value === '1',
            self::Text, self::Select => $value,
        };
    }

    /**
     * Нужен ли характеристике список допустимых значений.
     *
     * По этому признаку форма вехи 3.4 показывает редактор списка.
     */
    public function hasOptions(): bool
    {
        return $this === self::Select;
    }

    /**
     * Осмысленна ли для типа единица измерения — только у чисел.
     */
    public function hasUnit(): bool
    {
        return $this === self::Number;
    }
}
