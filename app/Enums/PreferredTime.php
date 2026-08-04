<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Удобное время звонка из формы заявки.
 *
 * Поле пришло из макета, а не из ТЗ: форма шире описанной в разделе 3.
 */
enum PreferredTime: string implements HasLabel
{
    use HasLabels;

    case Morning = 'morning';
    case Day = 'day';
    case Evening = 'evening';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Утро',
            self::Day => 'День',
            self::Evening => 'Вечер',
        };
    }
}
