<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasColors;
use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Статус обработки заявки (раздел 4 ТЗ: новая / в работе / закрыта).
 */
enum LeadStatus: string implements HasColor, HasLabel
{
    use HasColors;
    use HasLabels;

    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InProgress => 'В работе',
            self::Closed => 'Закрыта',
        };
    }

    /**
     * Цвет бейджа в админке. Значения — из палитры Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::InProgress => 'info',
            self::Closed => 'success',
        };
    }
}
