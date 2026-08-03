<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;

/**
 * Тип привода — фильтруемая характеристика каталога (раздел 3.2 ТЗ).
 *
 * `label()` даёт значение для строки «Привод» в карточке; в списке
 * каталога подпись достраивается словом «привод» на стороне вёрстки.
 */
enum DriveType: string
{
    use HasLabels;

    case Front = 'front';
    case Rear = 'rear';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Front => 'Передний',
            self::Rear => 'Задний',
            self::Full => 'Полный',
        };
    }
}
