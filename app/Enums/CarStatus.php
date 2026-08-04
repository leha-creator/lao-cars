<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Статус автомобиля в каталоге (раздел 3.2 ТЗ).
 *
 * «Продан» — не удаление: карточка остаётся ради истории и SEO,
 * но выпадает из выдачи каталога.
 */
enum CarStatus: string implements HasColor, HasLabel
{
    use HasLabels;

    case InStock = 'in_stock';
    case OnOrder = 'on_order';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наличии',
            self::OnOrder => 'Под заказ',
            self::Sold => 'Продан',
        };
    }

    /**
     * Цвет бейджа в админке. Значения — из палитры Filament.
     *
     * Живёт в enum-е по той же причине, что и подпись: хардкод цвета
     * в badge-колонке ресурса — копия словаря, которая разъезжается
     * с оригиналом при первом же новом статусе.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::OnOrder => 'warning',
            self::Sold => 'gray',
        };
    }
}
