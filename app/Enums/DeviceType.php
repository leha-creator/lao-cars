<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Тип устройства посетителя — для статистики посещений.
 *
 * Определяется простыми регулярными выражениями, без сторонней библиотеки:
 * нужен ответ на вопрос «какая доля заходит с телефона», а не модель
 * устройства. Сам User-Agent при этом не хранится нигде.
 */
enum DeviceType: string implements HasLabel
{
    use HasLabels;

    case Desktop = 'desktop';

    case Mobile = 'mobile';

    case Tablet = 'tablet';

    public function label(): string
    {
        return match ($this) {
            self::Desktop => 'Компьютеры',
            self::Mobile => 'Телефоны',
            self::Tablet => 'Планшеты',
        };
    }

    public static function fromUserAgent(?string $userAgent): self
    {
        if ($userAgent === null || $userAgent === '') {
            return self::Desktop;
        }

        // Планшет проверяется ПЕРВЫМ: в User-Agent iPad есть «Mobile»,
        // и обратный порядок записал бы каждый планшет телефоном.
        // Android без слова «Mobile» — планшет: так их различает сам Chrome.
        if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $userAgent) === 1
            || (preg_match('/android/i', $userAgent) === 1 && preg_match('/mobile/i', $userAgent) !== 1)) {
            return self::Tablet;
        }

        if (preg_match('/mobile|iphone|ipod|android|windows phone|opera mini|iemobile|blackberry/i', $userAgent) === 1) {
            return self::Mobile;
        }

        return self::Desktop;
    }
}
