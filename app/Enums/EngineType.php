<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;

/**
 * Тип двигателя — фильтруемая характеристика каталога (раздел 3.2 ТЗ).
 */
enum EngineType: string
{
    use HasLabels;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Бензин',
            self::Diesel => 'Дизель',
            self::Hybrid => 'Гибрид',
            self::Electric => 'Электро',
        };
    }

    /**
     * У электромобиля нет объёма двигателя — карточка не должна
     * показывать «0.0 л» там, где показывать нечего.
     */
    public function hasVolume(): bool
    {
        return $this !== self::Electric;
    }
}
