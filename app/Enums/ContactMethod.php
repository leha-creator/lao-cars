<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Предпочитаемый способ связи из формы заявки.
 *
 * Поле пришло из макета, а не из ТЗ: форма шире описанной в разделе 3.
 */
enum ContactMethod: string implements HasLabel
{
    use HasLabels;

    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Телефон',
            self::WhatsApp => 'WhatsApp',
            self::Telegram => 'Telegram',
        };
    }
}
